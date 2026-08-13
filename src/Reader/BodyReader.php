<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Reader;

use Dskripchenko\PhpDocx\Element\BlockElement;
use Dskripchenko\PhpDocx\Element\Bookmark;
use Dskripchenko\PhpDocx\Element\Field;
use Dskripchenko\PhpDocx\Element\Hyperlink;
use Dskripchenko\PhpDocx\Element\Footnote;
use Dskripchenko\PhpDocx\Element\Image;
use Dskripchenko\PhpDocx\Element\InlineElement;
use Dskripchenko\PhpDocx\Element\LineBreak;
use Dskripchenko\PhpDocx\Element\ListItem;
use Dskripchenko\PhpDocx\Element\ListNode;
use Dskripchenko\PhpDocx\Element\PageBreak;
use Dskripchenko\PhpDocx\Element\Paragraph;
use Dskripchenko\PhpDocx\Element\Run;
use Dskripchenko\PhpDocx\Style\ParagraphStyle;
use Dskripchenko\PhpDocx\Style\RunStyle;

/**
 * Phase 3 — body XML → list<BlockElement>.
 *
 * Walks the `<w:body>` (or a `<w:hdr>`/`<w:ftr>` — the same structure) and maps
 * `<w:p>` → Paragraph, `<w:tbl>` → null (phase 4 fills it in), `<w:sectPr>` →
 * skip (that is metadata).
 *
 * The run traversal inside a `<w:p>`:
 *  - `<w:r>` → a Run (with the effective styles from StylesResolver) or a
 *    LineBreak/PageBreak
 *  - `<w:t>` → the text
 *  - `<w:br w:type="page">` → a PageBreak (a split: everything after it goes
 *    into a new paragraph)
 *  - `<w:br/>` → a LineBreak
 *  - `<w:tab/>` → a "\t" in the text
 *  - `<w:hyperlink>` — proxied as inline children for now (phase 7 replaces it
 *    with a Hyperlink)
 *  - `<w:fldSimple>`/`<w:fldChar>` — inline text from the contentRuns for now
 *    (phase 7 replaces it with a Field)
 *  - `<w:bookmarkStart>`/`<w:bookmarkEnd>` — skipped for now (phase 7 replaces
 *    them with a Bookmark)
 *  - `<w:drawing>` — phase 6 fills it in
 *
 * The hooks for the following phases: TableReader, HyperlinkReader, ImageReader
 * and FieldReader are wired in through setters or the constructor later.
 */
final class BodyReader
{
    private ?TableReader $tableReader = null;

    public function __construct(
        private readonly StylesResolver $styles = new StylesResolver,
        private readonly NumberingDefinitions $numbering = new NumberingDefinitions,
        private readonly ?ImageReader $imageReader = null,
        private readonly ?DocxPackage $package = null,
        private readonly string $partPath = 'word/document.xml',
        private readonly ?FootnoteReader $footnotes = null,
    ) {}

    /**
     * The lazy initialization of TableReader (it has a circular dependency
     * with BodyReader, hence the laziness through a self-reference).
     */
    private function tableReader(): TableReader
    {
        return $this->tableReader ??= new TableReader($this, $this->styles->parser());
    }

    /**
     * @return list<BlockElement>
     */
    public function read(\DOMElement $body): array
    {
        $blocks = [];
        /** @var list<array{numId:int, ilvl:int, inlines:list<InlineElement>, style:ParagraphStyle}> $pendingList */
        $pendingList = [];
        $pendingNumId = null;

        $flushList = function () use (&$pendingList, &$pendingNumId, &$blocks): void {
            if ($pendingList === []) {
                return;
            }
            $blocks[] = $this->buildListNode($pendingList, $pendingNumId);
            $pendingList = [];
            $pendingNumId = null;
        };

        foreach ($body->childNodes as $child) {
            if (! $child instanceof \DOMElement || $child->namespaceURI !== OoxmlNs::W) {
                continue;
            }
            switch ($child->localName) {
                case 'p':
                    $item = $this->classifyParagraph($child);
                    if ($item !== null) {
                        // List paragraph.
                        if ($pendingNumId !== null && $pendingNumId !== $item['numId']) {
                            $flushList();
                        }
                        $pendingNumId = $item['numId'];
                        $pendingList[] = $item;
                        break;
                    }
                    // An ordinary paragraph.
                    $flushList();
                    foreach ($this->readParagraph($child) as $b) {
                        $blocks[] = $b;
                    }
                    break;
                case 'tbl':
                    $flushList();
                    $blocks[] = $this->tableReader()->read($child);
                    break;
                case 'sectPr':
                    break;
                default:
                    break;
            }
        }
        $flushList();

        return $blocks;
    }

    /**
     * When a paragraph is a list item (a numPr plus a registered numId) it
     * returns a descriptor. Otherwise null (an ordinary paragraph).
     *
     * @return array{numId:int, ilvl:int, inlines:list<InlineElement>, style:ParagraphStyle}|null
     */
    private function classifyParagraph(\DOMElement $p): ?array
    {
        [$paraStyle, $runBase, , $numId, $ilvl] = $this->styles->effectiveStylesForParagraph($p);
        if ($numId === null || ! $this->numbering->hasNumId($numId)) {
            return null;
        }
        $inlines = $this->readInlines($p, $runBase);
        // We remove the internal page breaks (Word rarely puts them into list
        // items; when they are there we flatten them as an ordinary inline).
        $cleanInlines = array_values(array_filter(
            $inlines,
            fn ($i) => ! $i instanceof PageBreak,
        ));

        return [
            'numId' => $numId,
            'ilvl' => max(0, $ilvl ?? 0),
            'inlines' => $cleanInlines,
            'style' => $paraStyle,
        ];
    }

    /**
     * Assembles a nested ListNode/ListItem tree out of a flat list of list
     * items (ordered by ilvl, or with ad-hoc ones).
     *
     * @param  list<array{numId:int, ilvl:int, inlines:list<InlineElement>, style:ParagraphStyle}>  $items
     */
    private function buildListNode(array $items, ?int $numId): ListNode
    {
        if ($numId === null) {
            // It should not happen — but a fallback.
            return new ListNode([]);
        }
        $minIlvl = $this->minIlvl($items);
        $root = $this->buildRecursive($items, 0, count($items), $minIlvl, $numId);
        if ($root instanceof ListNode) {
            return $root;
        }

        return new ListNode([]);
    }

    /**
     * @param  list<array{numId:int, ilvl:int, inlines:list<InlineElement>, style:ParagraphStyle}>  $items
     */
    private function minIlvl(array $items): int
    {
        $min = PHP_INT_MAX;
        foreach ($items as $it) {
            if ($it['ilvl'] < $min) {
                $min = $it['ilvl'];
            }
        }

        return $min === PHP_INT_MAX ? 0 : $min;
    }

    /**
     * Recursively builds a ListNode for items[from..to] at the given depth. The
     * items with ilvl == depth become siblings inside the current ListNode; the
     * items with ilvl > depth are nested inside the previous sibling.
     *
     * @param  list<array{numId:int, ilvl:int, inlines:list<InlineElement>, style:ParagraphStyle}>  $items
     */
    private function buildRecursive(array $items, int $from, int $to, int $depth, int $numId): ListNode
    {
        $siblings = [];
        $i = $from;
        while ($i < $to) {
            $cur = $items[$i];
            if ($cur['ilvl'] < $depth) {
                break; // отдадим обратно вверх
            }
            if ($cur['ilvl'] > $depth) {
                // the children of the previous sibling
                $j = $i;
                while ($j < $to && $items[$j]['ilvl'] > $depth) {
                    $j++;
                }
                $nested = $this->buildRecursive($items, $i, $j, $depth + 1, $numId);
                // Attach it as the nestedList of the last sibling, when there is one.
                if ($siblings !== []) {
                    $last = $siblings[count($siblings) - 1];
                    $siblings[count($siblings) - 1] = new ListItem($last->children, $nested, $last->style);
                } else {
                    // There is no previous sibling — we wrap it into an empty parent item.
                    $siblings[] = new ListItem([], $nested);
                }
                $i = $j;

                continue;
            }
            // ilvl == depth — an ordinary sibling.
            $siblings[] = new ListItem($cur['inlines'], null, $cur['style']);
            $i++;
        }

        $format = $this->numbering->formatFor($numId, $depth);
        $startAt = $this->numbering->startAtFor($numId, $depth);
        $ordered = $this->numbering->isOrdered($numId, $depth);

        return new ListNode(
            items: $siblings,
            ordered: $ordered,
            levelStart: 0, // дочерние уровни сами знают свой ilvl через nesting
            format: $format,
            startAt: $startAt,
        );
    }

    /**
     * Parses a `<w:p>` into one or more blocks (a PageBreak may split it).
     *
     * @return list<BlockElement>
     */
    public function readParagraph(\DOMElement $p): array
    {
        [$pStyle, $runBase, $headingLevel] = $this->styles->effectiveStylesForParagraph($p);
        $inlines = $this->readInlines($p, $runBase);

        // When a PageBreak is found among the inlines we split into two
        // paragraphs ("text-before [PageBreak] text-after").
        return $this->splitOnPageBreak($inlines, $pStyle, $headingLevel);
    }

    /**
     * The traversal of the inline children inside a `<w:p>` (or inside a hyperlink and the like).
     *
     * @return list<InlineElement>
     */
    public function readInlines(\DOMElement $parent, RunStyle $baseRunStyle): array
    {
        $out = [];
        // The bookmark stack: openBookmarks[id] = ['name'=>..., 'children'=>[],
        // 'targetIndex'=>...]. The children accumulate in $out — no separate
        // buffers are needed; we remember the from/to indexes and split later.
        // bookmarkStart: we remember the starting index; bookmarkEnd: we wrap
        // the range into a Bookmark and replace the slice in $out.
        $openBookmarks = [];

        // Complex-field state machine.
        // null | 'instr' | 'value'
        $fieldState = null;
        $fieldInstr = '';
        $fieldDefault = '';
        $fieldValueSeen = false;
        $fieldStyle = $baseRunStyle;

        foreach ($parent->childNodes as $child) {
            if (! $child instanceof \DOMElement || $child->namespaceURI !== OoxmlNs::W) {
                continue;
            }
            $local = $child->localName;
            if ($local === 'pPr') {
                continue;
            }

            if ($local === 'bookmarkStart') {
                $id = $child->getAttributeNS(OoxmlNs::W, 'id');
                $name = $child->getAttributeNS(OoxmlNs::W, 'name');
                if ($id !== '' && $name !== '') {
                    $openBookmarks[$id] = ['name' => $name, 'startIndex' => count($out)];
                }

                continue;
            }
            if ($local === 'bookmarkEnd') {
                $id = $child->getAttributeNS(OoxmlNs::W, 'id');
                if (isset($openBookmarks[$id])) {
                    $info = $openBookmarks[$id];
                    $captured = array_slice($out, $info['startIndex']);
                    $out = array_slice($out, 0, $info['startIndex']);
                    $out[] = new Bookmark($info['name'], array_values($captured));
                    unset($openBookmarks[$id]);
                }

                continue;
            }

            if ($local === 'hyperlink') {
                $hyperlink = $this->buildHyperlink($child, $baseRunStyle);
                if ($hyperlink !== null) {
                    $out[] = $hyperlink;
                } else {
                    // fallback — flatten
                    foreach ($this->readInlines($child, $baseRunStyle) as $i) {
                        $out[] = $i;
                    }
                }

                continue;
            }
            if ($local === 'fldSimple') {
                $instr = trim($child->getAttributeNS(OoxmlNs::W, 'instr'));
                if ($instr !== '') {
                    $out[] = new Field($instr, $baseRunStyle);
                }
                // the contained runs are ignored (Word's placeholder text)
                continue;
            }
            if ($local === 'r') {
                // A <w:r> may hold a fldChar or an instrText (a complex field).
                $runResult = $this->readRunWithFieldState(
                    $child,
                    $baseRunStyle,
                    $fieldState,
                    $fieldInstr,
                    $fieldStyle,
                    $fieldDefault,
                    $fieldValueSeen,
                );
                foreach ($runResult['inlines'] as $i) {
                    $out[] = $i;
                }
                $fieldState = $runResult['state'];
                $fieldInstr = $runResult['instr'];
                $fieldStyle = $runResult['style'];
                $fieldDefault = $runResult['default'];
                $fieldValueSeen = $runResult['valueSeen'];

                continue;
            }
            // unknown — skip
        }

        return $out;
    }

    private function buildHyperlink(\DOMElement $hyperlinkEl, RunStyle $baseRunStyle): ?Hyperlink
    {
        $rId = $hyperlinkEl->getAttributeNS(OoxmlNs::R, 'id');
        $anchor = $hyperlinkEl->hasAttributeNS(OoxmlNs::W, 'anchor')
            ? $hyperlinkEl->getAttributeNS(OoxmlNs::W, 'anchor')
            : '';
        $children = $this->readInlines($hyperlinkEl, $baseRunStyle);

        if ($anchor !== '') {
            return Hyperlink::internal($anchor, $children);
        }
        if ($rId !== '' && $this->package !== null) {
            try {
                $rel = $this->package->resolveRel($this->partPath, $rId);

                return new Hyperlink(href: $rel->target, children: $children);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * The hint of an unfilled form field — `w:ffData/w:textInput/w:default`.
     */
    private static function formFieldDefault(\DOMElement $fldChar): string
    {
        foreach ($fldChar->getElementsByTagNameNS(OoxmlNs::W, 'default') as $default) {
            $val = $default->getAttributeNS(OoxmlNs::W, 'val');
            if ($val !== '') {
                return $val;
            }
        }

        return '';
    }

    /**
     * A wrapper around readRun that also manages the complex-field state.
     *
     * @return array{inlines: list<InlineElement>, state: string|null, instr: string, style: RunStyle, default: string, valueSeen: bool}
     */
    private function readRunWithFieldState(
        \DOMElement $r,
        RunStyle $baseRunStyle,
        ?string $state,
        string $instr,
        RunStyle $fieldStyle,
        string $fieldDefault = '',
        bool $valueSeen = false,
    ): array {
        $runStyle = $this->styles->effectiveStylesForRun($r, $baseRunStyle);
        $emitted = [];
        $textBuffer = '';

        // A field's result is what Word shows on the screen. We suppress it
        // only for the fields we return as an element of our own: the page
        // number, the date, a MERGEFIELD. For the rest (FORMTEXT, MACROBUTTON,
        // REF) the result IS the document's content — in the policyholder
        // questionnaire the labels "Наименование страхователя" and "ИНН" were
        // lost that way.
        $suppressValue = false;
        foreach (['PAGE', 'NUMPAGES', 'SECTIONPAGES', 'DATE', 'TIME', 'CREATEDATE', 'SAVEDATE', 'PRINTDATE', 'MERGEFIELD'] as $regenerated) {
            if (str_starts_with(strtoupper(ltrim($instr)), $regenerated)) {
                $suppressValue = true;

                break;
            }
        }

        $flushText = function () use (&$emitted, &$textBuffer, &$state, &$valueSeen, $suppressValue, $runStyle): void {
            if ($textBuffer === '') {
                return;
            }
            if ($state === 'value') {
                if ($suppressValue) {
                    $textBuffer = '';

                    return;
                }
                $valueSeen = true;
            }
            $emitted[] = new Run($textBuffer, $runStyle);
            $textBuffer = '';
        };

        foreach ($r->childNodes as $child) {
            if (! $child instanceof \DOMElement || $child->namespaceURI !== OoxmlNs::W) {
                continue;
            }
            switch ($child->localName) {
                case 'fldChar':
                    $type = $child->getAttributeNS(OoxmlNs::W, 'fldCharType');
                    if ($type === 'begin') {
                        $flushText();
                        $state = 'instr';
                        $instr = '';
                        $fieldStyle = $runStyle;
                        $fieldDefault = self::formFieldDefault($child);
                        $valueSeen = false;
                    } elseif ($type === 'separate') {
                        $flushText();
                        $state = 'value';
                    } elseif ($type === 'end') {
                        $flushText();
                        $cleanInstr = trim($instr);
                        // Word shows an unfilled form field as the hint from
                        // `w:ffData/w:textInput/w:default` — for a reader of the
                        // document that IS the visible text.
                        if (! $valueSeen && $fieldDefault !== '') {
                            $emitted[] = new Run($fieldDefault, $fieldStyle);
                        }
                        if ($cleanInstr !== '') {
                            $emitted[] = new Field($cleanInstr, $fieldStyle);
                        }
                        $state = null;
                        $instr = '';
                        $fieldDefault = '';
                        $valueSeen = false;
                    }
                    break;
                case 'instrText':
                    if ($state === 'instr') {
                        $instr .= $child->textContent;
                    }
                    break;
                case 't':
                    if ($state === 'instr') {
                        $instr .= $child->textContent;
                    } else {
                        $textBuffer .= $child->textContent;
                    }
                    break;
                case 'tab':
                    if ($state !== 'value') {
                        $textBuffer .= "\t";
                    }
                    break;
                case 'noBreakHyphen':
                    if ($state !== 'value') {
                        $textBuffer .= "\u{2011}";
                    }
                    break;
                case 'softHyphen':
                    if ($state !== 'value') {
                        $textBuffer .= "\u{00AD}";
                    }
                    break;
                case 'br':
                    $flushText();
                    if ($state !== 'value') {
                        $type = $child->getAttributeNS(OoxmlNs::W, 'type');
                        $emitted[] = $type === 'page' ? new PageBreak : new LineBreak;
                    }
                    break;
                case 'footnoteReference':
                    $flushText();
                    if ($this->footnotes !== null && $state !== 'value') {
                        $id = $child->getAttributeNS(OoxmlNs::W, 'id');
                        $text = ctype_digit(ltrim($id, '-')) ? $this->footnotes->text((int) $id) : null;
                        if ($text !== null) {
                            $emitted[] = new Footnote($text);
                        }
                    }
                    break;
                case 'drawing':
                    $flushText();
                    if ($this->imageReader !== null && $state !== 'value') {
                        $image = $this->imageReader->read($child);
                        if ($image instanceof Image) {
                            $emitted[] = $image;
                        }
                    }
                    break;
                case 'rPr':
                    break;
            }
        }
        $flushText();

        return [
            'inlines' => $emitted,
            'state' => $state,
            'instr' => $instr,
            'style' => $fieldStyle,
            'default' => $fieldDefault,
            'valueSeen' => $valueSeen,
        ];
    }

    /**
     * Splits an inline list into paragraphs at the page breaks.
     *
     * @param  list<InlineElement>  $inlines
     * @return list<BlockElement>
     */
    private function splitOnPageBreak(array $inlines, ParagraphStyle $style, ?int $headingLevel): array
    {
        $blocks = [];
        $current = [];
        foreach ($inlines as $i) {
            if ($i instanceof PageBreak) {
                if ($current !== []) {
                    $blocks[] = new Paragraph($current, $style, $headingLevel);
                    $current = [];
                }
                $blocks[] = $i;

                continue;
            }
            $current[] = $i;
        }
        if ($current !== [] || $blocks === []) {
            $blocks[] = new Paragraph($current, $style, $headingLevel);
        }

        return $blocks;
    }
}
