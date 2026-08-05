<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Reader;

use Dskripchenko\PhpDocx\Element\BlockElement;
use Dskripchenko\PhpDocx\Element\Bookmark;
use Dskripchenko\PhpDocx\Element\Field;
use Dskripchenko\PhpDocx\Element\Hyperlink;
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
 * Walks `<w:body>` (или `<w:hdr>`/`<w:ftr>` — та же структура), маппит
 * `<w:p>` → Paragraph, `<w:tbl>` → null (Phase 4 заполнит), `<w:sectPr>`
 * → skip (это metadata).
 *
 * Run-обход внутри `<w:p>`:
 *  - `<w:r>` → Run (с effective styles от StylesResolver) или LineBreak/PageBreak
 *  - `<w:t>` → текст
 *  - `<w:br w:type="page">` → PageBreak (split: всё что после идёт в новый параграф)
 *  - `<w:br/>` → LineBreak
 *  - `<w:tab/>` → "\t" в text
 *  - `<w:hyperlink>` — пока проксируем как inline children (Phase 7 заменит на Hyperlink)
 *  - `<w:fldSimple>`/`<w:fldChar>` — пока inline-текст из contentRuns (Phase 7 заменит на Field)
 *  - `<w:bookmarkStart>`/`<w:bookmarkEnd>` — пока skip (Phase 7 заменит на Bookmark)
 *  - `<w:drawing>` — Phase 6 заполнит
 *
 * Hooks для следующих phase'ов: TableReader, HyperlinkReader, ImageReader,
 * FieldReader подключаются через сеттеры/конструктор позже.
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
    ) {}

    /**
     * Lazy-инициализация TableReader (циклическая зависимость с BodyReader,
     * поэтому делаем lazy через self-reference).
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
                    // Обычный параграф.
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
     * Если параграф — list-item (numPr+зарегистрированный numId), возвращает
     * descriptor. Иначе null (обычный параграф).
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
        // Убираем internal PageBreaks (Word редко кладёт их в list-items;
        // если они есть — flatten как обычный inline).
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
     * Из flat-списка list-items (по возрастанию или ad-hoc ilvl) собирает
     * дерево ListNode/ListItem с nesting'ом.
     *
     * @param  list<array{numId:int, ilvl:int, inlines:list<InlineElement>, style:ParagraphStyle}>  $items
     */
    private function buildListNode(array $items, ?int $numId): ListNode
    {
        if ($numId === null) {
            // Не должно случаться — но fallback.
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
     * Рекурсивно строит ListNode для items[from..to] на заданном depth.
     * Items с ilvl == depth → siblings в текущем ListNode; items с
     * ilvl > depth → nested внутрь предыдущего siblng'а.
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
                // children предыдущего sibling'а
                $j = $i;
                while ($j < $to && $items[$j]['ilvl'] > $depth) {
                    $j++;
                }
                $nested = $this->buildRecursive($items, $i, $j, $depth + 1, $numId);
                // Прикрепить как nestedList последнего sibling'а, если есть.
                if ($siblings !== []) {
                    $last = $siblings[count($siblings) - 1];
                    $siblings[count($siblings) - 1] = new ListItem($last->children, $nested, $last->style);
                } else {
                    // Нет предыдущего sibling'а — оборачиваем в пустой parent-item.
                    $siblings[] = new ListItem([], $nested);
                }
                $i = $j;

                continue;
            }
            // ilvl == depth — обычный sibling.
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
     * Парсит `<w:p>` в один или несколько Block'ов (PageBreak может split'нуть).
     *
     * @return list<BlockElement>
     */
    public function readParagraph(\DOMElement $p): array
    {
        [$pStyle, $runBase, $headingLevel] = $this->styles->effectiveStylesForParagraph($p);
        $inlines = $this->readInlines($p, $runBase);

        // Если в inlines обнаружен PageBreak — разделяем на 2 параграфа
        // ("text-before [PageBreak] text-after").
        return $this->splitOnPageBreak($inlines, $pStyle, $headingLevel);
    }

    /**
     * Обход inline children внутри `<w:p>` (или внутри hyperlink/etc.).
     *
     * @return list<InlineElement>
     */
    public function readInlines(\DOMElement $parent, RunStyle $baseRunStyle): array
    {
        $out = [];
        // Bookmark-stack: openBookmarks[id] = ['name'=>..., 'children'=>[], 'targetIndex'=>...]
        // children накапливаются в $out — отдельные «buffers» не нужны;
        // мы запомним index'ы from/to и потом split'нём.
        // bookmarkStart: запоминаем индекс старта; bookmarkEnd: оборачиваем
        // диапазон в Bookmark и заменяем slice в $out.
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
                // contained runs — игнорируем (placeholder text Word'а)
                continue;
            }
            if ($local === 'r') {
                // Внутри <w:r> может быть fldChar/instrText (complex field).
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
     * Подсказка незаполненного поля формы — `w:ffData/w:textInput/w:default`.
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
     * Wrapper над readRun который также управляет complex-field state.
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

        // Результат поля — это то, что Word показывает на экране. Подавляем
        // его только у полей, которые мы отдаём собственным элементом: номер
        // страницы, дата, MERGEFIELD. У остальных (FORMTEXT, MACROBUTTON,
        // REF) результат и есть содержимое документа — в анкете страхователя
        // так терялись подписи «Наименование страхователя», «ИНН».
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
                        // Незаполненное поле формы Word показывает подсказкой
                        // из `w:ffData/w:textInput/w:default` — для читателя
                        // документа это и есть видимый текст.
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
     * Разделяет inline-список на параграфы по PageBreak'ам.
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
