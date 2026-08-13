<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Html;

use Dskripchenko\PhpDocx\Document;
use Dskripchenko\PhpDocx\Element\BlockElement;
use Dskripchenko\PhpDocx\Element\Bookmark;
use Dskripchenko\PhpDocx\Element\Field;
use Dskripchenko\PhpDocx\Element\HorizontalRule;
use Dskripchenko\PhpDocx\Element\Hyperlink;
use Dskripchenko\PhpDocx\Element\Image;
use Dskripchenko\PhpDocx\Element\ImageFormat;
use Dskripchenko\PhpDocx\Element\InlineElement;
use Dskripchenko\PhpDocx\Element\LineBreak;
use Dskripchenko\PhpDocx\Element\ListFormat;
use Dskripchenko\PhpDocx\Element\ListItem;
use Dskripchenko\PhpDocx\Element\ListNode;
use Dskripchenko\PhpDocx\Element\PageBreak;
use Dskripchenko\PhpDocx\Element\Paragraph;
use Dskripchenko\PhpDocx\Element\Run;
use Dskripchenko\PhpDocx\Element\Table;
use Dskripchenko\PhpDocx\Element\TableCell;
use Dskripchenko\PhpDocx\Element\TableRow;
use Dskripchenko\PhpDocx\Html\StyleApplier\CellStyleApplier;
use Dskripchenko\PhpDocx\Html\StyleApplier\ParagraphStyleApplier;
use Dskripchenko\PhpDocx\Html\StyleApplier\RunStyleApplier;
use Dskripchenko\PhpDocx\Html\StyleApplier\TableStyleApplier;
use Dskripchenko\PhpDocx\Section;
use Dskripchenko\PhpDocx\Style\CellStyle;
use Dskripchenko\PhpDocx\Style\ParagraphStyle;
use Dskripchenko\PhpDocx\Style\PageSetup;
use Dskripchenko\PhpDocx\Style\RunStyle;
use Dskripchenko\PhpDocx\Style\TableStyle;

/**
 * HTML → Document converter. Phase 2 implementation.
 *
 * It parses HTML5 through `DOMDocument::loadHTML` (in lenient mode), walks the
 * tree and maps the elements onto typed value objects.
 *
 *  - Only an inline `style="..."` counts for the styles; the CSS rules are
 *    inlined by the caller upstream.
 *  - Images: only `<img src="data:image/...;base64,...">`. URL images
 *    (`http://`, `file://`) are ignored (the caller must resolve them upstream).
 *  - Block versus inline is decided by the tag's presence in the `BLOCK_TAGS`
 *    set.
 */
final class Converter
{
    /** The tags that turn into a BlockElement or cause one to be split. */
    private const BLOCK_TAGS = ['p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'table', 'hr', 'ul', 'ol', 'blockquote', 'pagebreak', 'pre', 'dl', 'figure', 'section', 'article', 'aside', 'header', 'footer', 'nav', 'main'];

    public function __construct(
        private readonly PageSetup $defaultPageSetup = new PageSetup,
        /**
         * Optional HTML preprocessing seam — e.g. a CSS inliner turning
         * `<style>` blocks and classes into the inline styles this
         * converter understands. Applied by fromHtmlWithStyles().
         */
        private readonly ?HtmlPreprocessor $preprocessor = null,
    ) {}

    /**
     * Like {@see fromHtml()}, but runs the HTML through the configured
     * {@see HtmlPreprocessor} first (defaults to the CSS inliner when the
     * optional `tijsverkoyen/css-to-inline-styles` package is installed).
     * Use for HTML carrying `<style>` blocks or class-based styling.
     */
    public function fromHtmlWithStyles(
        string $body,
        ?string $header = null,
        ?string $footer = null,
        ?PageSetup $pageSetup = null,
        ?string $watermarkText = null,
    ): Document {
        $pre = $this->preprocessor ?? new CssInlinerPreprocessor;

        return $this->fromHtml(
            $pre->preprocess($body),
            $header !== null ? $pre->preprocess($header) : null,
            $footer !== null ? $pre->preprocess($footer) : null,
            $pageSetup,
            $watermarkText,
        );
    }

    public function fromHtml(
        string $body,
        ?string $header = null,
        ?string $footer = null,
        ?PageSetup $pageSetup = null,
        ?string $watermarkText = null,
    ): Document {
        return new Document(
            section: new Section(
                body: $this->parseBlocks($body),
                header: $header !== null ? $this->parseBlocks($header) : [],
                footer: $footer !== null ? $this->parseBlocks($footer) : [],
                pageSetup: $pageSetup ?? $this->defaultPageSetup,
            ),
            watermarkText: $watermarkText,
        );
    }

    /**
     * @return list<BlockElement>
     */
    private function parseBlocks(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        $doc = $this->loadHtml($html);
        $body = $doc->getElementsByTagName('body')->item(0);
        if (! $body instanceof \DOMElement) {
            return [];
        }

        // The body's inline style is the basis for every child. We simulate
        // the CSS inheritance: the font-family, colour and font-size of the
        // <body> apply to every descendant. CssInliner (tijsverkoyen) does NOT
        // propagate the inherited properties downwards — without this every run
        // would come out without an explicit font.
        $bodyCss = InlineStyleParser::parse($body->getAttribute('style'));
        $rootRun = RunStyleApplier::apply(new RunStyle, $bodyCss);
        $rootPara = ParagraphStyleApplier::apply(new ParagraphStyle, $bodyCss);

        return $this->processChildNodes($body, $rootRun, $rootPara);
    }

    /**
     * @return list<BlockElement>
     */
    private function processChildNodes(\DOMElement $parent, RunStyle $runStyle, ParagraphStyle $paraStyle): array
    {
        $blocks = [];
        /** @var list<InlineElement> $inlineBuffer */
        $inlineBuffer = [];

        $flushInline = function () use (&$blocks, &$inlineBuffer, $paraStyle): void {
            $inlineBuffer = $this->trimInline($inlineBuffer);
            if ($inlineBuffer !== []) {
                $blocks[] = new Paragraph($inlineBuffer, $paraStyle);
                $inlineBuffer = [];
            }
        };

        foreach ($parent->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $text = $child->textContent;
                if ($text === '') {
                    continue;
                }
                $inlineBuffer[] = new Run($this->normalizeText($text), $runStyle);

                continue;
            }
            if (! $child instanceof \DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);
            if (in_array($tag, self::BLOCK_TAGS, true)) {
                $flushInline();
                $produced = $this->processBlockElement($child, $runStyle, $paraStyle);
                foreach ($produced as $b) {
                    $blocks[] = $b;
                }

                continue;
            }

            // Inline element (span, strong, em, br, img inline, a, etc.)
            $inlineProduced = $this->processInlineElement($child, $runStyle);
            foreach ($inlineProduced as $i) {
                $inlineBuffer[] = $i;
            }
        }

        $flushInline();

        return $blocks;
    }

    /**
     * @return list<BlockElement>
     */
    private function processBlockElement(\DOMElement $node, RunStyle $runStyle, ParagraphStyle $paraStyle): array
    {
        $tag = strtolower($node->tagName);
        $css = InlineStyleParser::parse($node->getAttribute('style'));
        $localPara = ParagraphStyleApplier::apply($paraStyle, $css);
        $localRun = RunStyleApplier::apply($runStyle, $css);

        if (in_array($tag, ['p', 'div'], true)) {
            $marker = $this->blockByClass($node, $localRun, $localPara);
            if ($marker !== null) {
                return $marker;
            }
        }

        return match ($tag) {
            'p', 'div' => $this->parseParagraph($node, $localRun, $localPara, headingLevel: null),
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6' =>
                $this->parseParagraph($node, $localRun, $localPara, headingLevel: (int) substr($tag, 1)),
            'table' => [$this->parseTable($node, $localRun, $localPara)],
            'hr' => $this->parseHr($node),
            'pagebreak' => [new PageBreak],
            'ul', 'ol' => $this->parseList($node, $localRun, $localPara, ordered: $tag === 'ol'),
            'blockquote' => $this->processChildNodes($node, $localRun, $localPara->copy(indentLeftTwips: 720)),
            // The semantic block tags are rendered as divs (their children).
            'section', 'article', 'aside', 'header', 'footer', 'nav', 'main' =>
                $this->processChildNodes($node, $localRun, $localPara),
            // pre preserves the whitespace and uses Courier New. The text inside goes as it is.
            'pre' => $this->parsePreformatted($node, $localRun->withFontFamily('Courier New'), $localPara),
            'dl' => $this->parseDefinitionList($node, $localRun, $localPara),
            'figure' => $this->parseFigure($node, $localRun, $localPara),
            default => [],
        };
    }

    /**
     * `<dl><dt>term</dt><dd>def</dd></dl>` becomes pairs of paragraphs:
     *  - the <dt> is rendered bold (without an indent)
     *  - the <dd> is rendered with a left indent of 720 twips (0.5")
     *
     * @return list<BlockElement>
     */
    private function parseDefinitionList(\DOMElement $node, RunStyle $runStyle, ParagraphStyle $paraStyle): array
    {
        $blocks = [];
        foreach ($node->childNodes as $child) {
            if (! $child instanceof \DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if ($tag === 'dt') {
                $inlines = $this->collectInline($child, $runStyle->withBold());
                $blocks[] = new Paragraph($this->trimInline($inlines), $paraStyle);
            } elseif ($tag === 'dd') {
                $inlines = $this->collectInline($child, $runStyle);
                $blocks[] = new Paragraph(
                    $this->trimInline($inlines),
                    $paraStyle->copy(indentLeftTwips: 720),
                );
            }
        }

        return $blocks;
    }

    /**
     * `<figure>...<figcaption>caption</figcaption></figure>` → image + caption paragraph.
     *
     * @return list<BlockElement>
     */
    private function parseFigure(\DOMElement $node, RunStyle $runStyle, ParagraphStyle $paraStyle): array
    {
        $blocks = [];
        $captionText = null;
        foreach ($node->childNodes as $child) {
            if (! $child instanceof \DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if ($tag === 'figcaption') {
                $captionText = trim($child->textContent);

                continue;
            }
            // The remaining children are rendered through the regular block pipeline.
            $produced = $this->processBlockElement(
                $child,
                $runStyle,
                $paraStyle->copy(alignment: \Dskripchenko\PhpDocx\Style\Alignment::Center),
            );
            foreach ($produced as $b) {
                $blocks[] = $b;
            }
            // When it is inline (an image inside a figure) we wrap it
            if ($produced === [] && in_array($tag, ['img', 'a'], true)) {
                $inlines = $this->processInlineElement($child, $runStyle);
                if ($inlines !== []) {
                    $blocks[] = new Paragraph(
                        $inlines,
                        $paraStyle->copy(alignment: \Dskripchenko\PhpDocx\Style\Alignment::Center),
                    );
                }
            }
        }
        if ($captionText !== null && $captionText !== '') {
            $blocks[] = new Paragraph(
                [new Run($captionText, $runStyle->withItalic()->withSizeHalfPoints(20))],
                $paraStyle->copy(
                    alignment: \Dskripchenko\PhpDocx\Style\Alignment::Center,
                    spaceBeforeTwips: 40,
                    spaceAfterTwips: 120,
                ),
            );
        }

        return $blocks;
    }

    /**
     * @return list<BlockElement>
     */
    private function parsePreformatted(\DOMElement $node, RunStyle $runStyle, ParagraphStyle $paraStyle): array
    {
        // The whitespace and the newlines are preserved. The inner <code>, <br>
        // and so on are parsed as inline, but the text content is not
        // normalized.
        $inlines = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) {
                if ($child->textContent !== '') {
                    $inlines[] = new Run($child->textContent, $runStyle);
                }

                continue;
            }
            if ($child instanceof \DOMElement) {
                foreach ($this->processInlineElement($child, $runStyle) as $i) {
                    $inlines[] = $i;
                }
            }
        }
        if ($inlines === []) {
            return [];
        }

        return [new Paragraph($inlines, $paraStyle)];
    }

    /**
     * @return list<BlockElement>
     */
    private function parseParagraph(\DOMElement $node, RunStyle $runStyle, ParagraphStyle $paraStyle, ?int $headingLevel): array
    {
        // When a block <p>/<div>/<h*> holds only inline children we assemble a
        // single Paragraph. When there are block-level children (a nested
        // <table>, a <ul> and so on) we recurse and create no paragraph.
        if ($this->containsBlockChild($node)) {
            return $this->processChildNodes($node, $runStyle, $paraStyle);
        }

        $inlines = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $text = $this->normalizeText($child->textContent);
                if ($text !== '') {
                    $inlines[] = new Run($text, $runStyle);
                }

                continue;
            }
            if ($child instanceof \DOMElement) {
                foreach ($this->processInlineElement($child, $runStyle) as $i) {
                    $inlines[] = $i;
                }
            }
        }

        $inlines = $this->trimInline($inlines);
        if ($inlines === []) {
            return [];
        }

        return [new Paragraph($inlines, $paraStyle, $headingLevel)];
    }

    /**
     * @return list<InlineElement>
     */
    private function processInlineElement(\DOMElement $node, RunStyle $runStyle): array
    {
        $tag = strtolower($node->tagName);
        $css = InlineStyleParser::parse($node->getAttribute('style'));
        $local = RunStyleApplier::apply($runStyle, $css);

        // The mark tags inherit and add their own flag (through the with* helpers).
        $marked = match ($tag) {
            'strong', 'b' => $local->withBold(),
            'em', 'i', 'cite', 'dfn', 'var', 'address' => $local->withItalic(),
            'u' => $local->withUnderline(),
            's', 'del', 'strike' => $local->withStrikethrough(),
            'sup' => $local->withSuperscript(),
            'sub' => $local->withSubscript(),
            // Highlighted text (a CSS background-color on a span does NOT work in OOXML).
            'mark' => $local->withHighlight('yellow'),
            // The monospaced inline tags.
            'code', 'kbd', 'samp', 'tt' => $local->withFontFamily('Courier New'),
            // A smaller font (about 83% of the current one).
            'small' => $local->withSizeHalfPoints(
                $local->sizeHalfPoints !== null
                    ? max(10, (int) round($local->sizeHalfPoints * 0.83))
                    : 18,
            ),
            // An inline quotation (italic; the quotation marks could be added later, for now it is just italic).
            'q' => $local->withItalic(),
            default => $local,
        };

        // A marker by class (`<span class="page-number">`) is a second spelling
        // of the same thing as the custom tags below. That is how HTML editors
        // write fields: they would not preserve a tag of their own, while a
        // class on a span survives any markup cleanup.
        $byClass = $this->fieldByClass($node, $marked);
        if ($byClass !== null) {
            return [$byClass];
        }

        return match ($tag) {
            'br' => [new LineBreak],
            'img' => $this->parseInlineImage($node) !== null ? [$this->parseInlineImage($node)] : [],
            'a' => $this->parseAnchor($node, $marked),
            // The custom tags for the field codes:
            'page-number' => [Field::page($marked)],
            'page-total' => [Field::pageTotal($marked)],
            'current-date' => [Field::date(
                $node->getAttribute('format') !== '' ? $node->getAttribute('format') : 'dd.MM.yyyy',
                $marked,
            )],
            'current-time' => [Field::time(
                $node->getAttribute('format') !== '' ? $node->getAttribute('format') : 'HH:mm',
                $marked,
            )],
            // The mark tags and the unknown inline tags (span and so on) —
            // we simply pass the style down into the children.
            default => $this->collectInline($node, $marked),
        };
    }

    /**
     * An `<a>` comes in four kinds:
     *  - <a href="https://...">  — an external link (a Hyperlink with an href)
     *  - <a href="#anchor">      — an internal one (a Hyperlink with an anchor)
     *  - <a id="anchor"> or <a name="anchor"> — a bookmark
     *  - <a href="..." id="...">  — both (a bookmark wrapped into a link)
     *
     * A bookmark name in OOXML: up to 40 characters, starting with a letter or
     * `_`, without spaces. We sanitize the HTML id to that rule.
     *
     * @return list<InlineElement>
     */
    private function parseAnchor(\DOMElement $node, RunStyle $runStyle): array
    {
        $href = $node->getAttribute('href');
        $id = $node->getAttribute('id');
        $name = $node->getAttribute('name');
        $bookmarkName = $id !== '' ? $id : ($name !== '' ? $name : null);
        $children = $this->collectInline($node, $runStyle);

        // A pure bookmark — without an href.
        if ($href === '' && $bookmarkName !== null) {
            return [new Bookmark($this->sanitizeBookmarkName($bookmarkName), $children)];
        }

        if ($href !== '' && str_starts_with($href, '#')) {
            $anchor = $this->sanitizeBookmarkName(substr($href, 1));
            $link = Hyperlink::internal($anchor, $children);

            if ($bookmarkName !== null) {
                return [new Bookmark($this->sanitizeBookmarkName($bookmarkName), [$link])];
            }

            return [$link];
        }

        if ($href !== '') {
            $link = new Hyperlink(href: $href, children: $children);
            if ($bookmarkName !== null) {
                return [new Bookmark($this->sanitizeBookmarkName($bookmarkName), [$link])];
            }

            return [$link];
        }

        // An <a> with neither href nor id/name — we simply pass the style down.
        return $children;
    }

    private function sanitizeBookmarkName(string $raw): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_]/', '_', $raw) ?? '_';
        if ($clean === '' || ! preg_match('/^[A-Za-z_]/', $clean)) {
            $clean = '_'.$clean;
        }

        return substr($clean, 0, 40);
    }

    /**
     * A recursive collection of the inline children with the style applied.
     *
     * @return list<InlineElement>
     */
    private function collectInline(\DOMElement $node, RunStyle $runStyle): array
    {
        $out = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $text = $this->normalizeText($child->textContent);
                if ($text !== '') {
                    $out[] = new Run($text, $runStyle);
                }

                continue;
            }
            if ($child instanceof \DOMElement) {
                foreach ($this->processInlineElement($child, $runStyle) as $i) {
                    $out[] = $i;
                }
            }
        }

        return $out;
    }

    private function parseTable(\DOMElement $node, RunStyle $runStyle, ParagraphStyle $paraStyle): Table
    {
        $css = InlineStyleParser::parse($node->getAttribute('style'));
        $attrs = $this->collectAttrs($node);
        $tableStyle = TableStyleApplier::apply(new TableStyle, $css, $attrs);

        // The <caption> text (optional) is rendered before the <w:tbl>, with the Caption style.
        $caption = null;
        foreach ($node->childNodes as $c) {
            if ($c instanceof \DOMElement && strtolower($c->tagName) === 'caption') {
                $cText = trim($c->textContent);
                if ($cText !== '') {
                    $caption = $cText;
                }
                break;
            }
        }

        // <colgroup>/<col> — explicit column widths
        $gridColumnsTwips = $this->parseColgroup($node);

        $rows = [];
        foreach ($this->directTableRows($node) as $tr) {
            $rows[] = $this->parseTableRow($tr, $runStyle, $paraStyle);
        }

        if ($rows !== []) {
            $rows[0] = $this->balanceFirstRowWidths($rows[0]);
        }
        // Rowspan: we automatically inject the continue cells (<w:vMerge/>)
        // into the following rows for every cell with rowSpan > 1.
        $rows = $this->expandRowSpans($rows);

        return new Table($rows, $tableStyle, $caption, $gridColumnsTwips);
    }

    /**
     * Walks the rows and, for every cell with rowSpan > 1, adds the matching
     * continue cells into the following rows. Otherwise an OOXML rowspan does
     * not work: a w:vMerge is expected in EVERY row the merge touches.
     *
     * @param  list<TableRow>  $rows
     * @return list<TableRow>
     */
    private function expandRowSpans(array $rows): array
    {
        if (count($rows) <= 1) {
            return $rows;
        }

        // For each row produce an expanded cell list.
        // pendingByCol[colIndex] = remaining_continuations
        // continueStyle[colIndex] = the CellStyle of the continue cell (we
        //   inherit the width, gridSpan and borders of the originating cell, to
        //   keep the columns aligned).
        $pendingByCol = [];
        $continueStyle = [];

        $newRows = [];
        foreach ($rows as $row) {
            $newCells = [];
            $colCursor = 0;
            $origIdx = 0;

            // We step through the columns, inserting the continue cells at the
            // pending positions and copying the original cells into the rest.
            while ($origIdx < count($row->cells) || isset($pendingByCol[$colCursor])) {
                if (isset($pendingByCol[$colCursor]) && $pendingByCol[$colCursor] > 0) {
                    // Place continue-cell at this column.
                    $style = $continueStyle[$colCursor];
                    $contStyle = new \Dskripchenko\PhpDocx\Style\CellStyle(
                        widthTwips: $style->widthTwips,
                        widthPercent: $style->widthPercent,
                        verticalAlign: $style->verticalAlign,
                        borders: $style->borders,
                        gridSpan: $style->gridSpan,
                        vMergeContinue: true,
                    );
                    $newCells[] = new \Dskripchenko\PhpDocx\Element\TableCell(
                        children: [new Paragraph([])],
                        style: $contStyle,
                    );
                    $pendingByCol[$colCursor]--;
                    if ($pendingByCol[$colCursor] === 0) {
                        unset($pendingByCol[$colCursor]);
                        unset($continueStyle[$colCursor]);
                    }
                    $colCursor += $style->gridSpan;

                    continue;
                }

                if ($origIdx >= count($row->cells)) {
                    break;
                }
                $cell = $row->cells[$origIdx];
                $newCells[] = $cell;
                if ($cell->style->rowSpan > 1) {
                    $pendingByCol[$colCursor] = $cell->style->rowSpan - 1;
                    $continueStyle[$colCursor] = $cell->style;
                }
                $colCursor += max(1, $cell->style->gridSpan);
                $origIdx++;
            }

            $newRows[] = new TableRow($newCells, $row->isHeader, $row->heightTwips);
        }

        return $newRows;
    }

    /**
     * @return list<int>|null
     */
    private function parseColgroup(\DOMElement $table): ?array
    {
        $widths = [];
        $found = false;
        foreach ($table->childNodes as $child) {
            if (! $child instanceof \DOMElement || strtolower($child->tagName) !== 'colgroup') {
                continue;
            }
            $found = true;
            foreach ($child->childNodes as $col) {
                if (! $col instanceof \DOMElement || strtolower($col->tagName) !== 'col') {
                    continue;
                }
                $widths[] = $this->parseColWidth($col);
            }
            break;
        }
        if (! $found) {
            return null;
        }
        $hasAny = false;
        foreach ($widths as $w) {
            if ($w !== null) {
                $hasAny = true;
                break;
            }
        }
        if (! $hasAny) {
            return null;
        }
        // The default for the columns without a width is an equal share of what is left of 9000 twips.
        $sum = array_sum(array_filter($widths, fn ($w) => $w !== null));
        $nullCount = count(array_filter($widths, fn ($w) => $w === null));
        $fallback = $nullCount > 0 ? max(720, (int) floor(max(0, 9000 - $sum) / $nullCount)) : 2000;

        return array_map(static fn (?int $w): int => $w ?? $fallback, $widths);
    }

    private function parseColWidth(\DOMElement $col): ?int
    {
        $attr = $col->getAttribute('width');
        if ($attr !== '') {
            $twips = LengthParser::parseTwips($attr);
            if ($twips !== null) {
                return $twips;
            }
            if (preg_match('/^\d+$/', $attr) === 1) {
                return (int) $attr * 15; // bare number = px
            }
        }
        $css = InlineStyleParser::parse($col->getAttribute('style'));
        if (isset($css['width'])) {
            $twips = LengthParser::parseTwips($css['width']);
            if ($twips !== null) {
                return $twips;
            }
        }

        return null;
    }

    /**
     * Distributes the missing cell widths of the first row up to 100% (5000
     * pct). It is used as the source of the gridCols, so the first row decides.
     */
    private function balanceFirstRowWidths(TableRow $row): TableRow
    {
        if ($row->cells === []) {
            return $row;
        }

        // We count what we have; 5000 pct = 100%; 100 mm is about 5670 twips.
        $totalPctClaimed = 0;
        $cellsWithoutWidth = [];
        foreach ($row->cells as $i => $cell) {
            if ($cell->style->widthPercent !== null) {
                $totalPctClaimed += $cell->style->widthPercent;
            } elseif ($cell->style->widthTwips !== null) {
                // nothing: explicit twips are left alone
            } else {
                $cellsWithoutWidth[] = $i;
            }
        }

        if ($cellsWithoutWidth === []) {
            return $row;
        }

        // What is left available, in pct.
        $remaining = max(0, 5000 - $totalPctClaimed);
        $perCell = (int) floor($remaining / count($cellsWithoutWidth));
        if ($perCell <= 0) {
            return $row;
        }

        $newCells = $row->cells;
        foreach ($cellsWithoutWidth as $i) {
            $old = $newCells[$i];
            $newCells[$i] = new \Dskripchenko\PhpDocx\Element\TableCell(
                children: $old->children,
                style: new \Dskripchenko\PhpDocx\Style\CellStyle(
                    widthTwips: $old->style->widthTwips,
                    widthPercent: $perCell,
                    paddingTopTwips: $old->style->paddingTopTwips,
                    paddingRightTwips: $old->style->paddingRightTwips,
                    paddingBottomTwips: $old->style->paddingBottomTwips,
                    paddingLeftTwips: $old->style->paddingLeftTwips,
                    verticalAlign: $old->style->verticalAlign,
                    backgroundColor: $old->style->backgroundColor,
                    borders: $old->style->borders,
                    gridSpan: $old->style->gridSpan,
                    rowSpan: $old->style->rowSpan,
                ),
            );
        }

        return new TableRow(
            cells: $newCells,
            isHeader: $row->isHeader,
            heightTwips: $row->heightTwips,
        );
    }

    /**
     * @return iterable<\DOMElement>
     */
    private function directTableRows(\DOMElement $table): iterable
    {
        foreach ($table->childNodes as $child) {
            if (! $child instanceof \DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if ($tag === 'tr') {
                yield $child;
            } elseif (in_array($tag, ['thead', 'tbody', 'tfoot'], true)) {
                foreach ($child->childNodes as $grand) {
                    if ($grand instanceof \DOMElement && strtolower($grand->tagName) === 'tr') {
                        yield $grand;
                    }
                }
            }
        }
    }

    private function parseTableRow(\DOMElement $tr, RunStyle $runStyle, ParagraphStyle $paraStyle): TableRow
    {
        $isHeader = $tr->parentNode instanceof \DOMElement
            && strtolower($tr->parentNode->tagName) === 'thead';

        $cells = [];
        foreach ($tr->childNodes as $child) {
            if (! $child instanceof \DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if ($tag !== 'td' && $tag !== 'th') {
                continue;
            }
            $cells[] = $this->parseTableCell($child, $runStyle, $paraStyle, isHeader: $tag === 'th');
        }

        return new TableRow($cells, isHeader: $isHeader);
    }

    private function parseTableCell(\DOMElement $cell, RunStyle $runStyle, ParagraphStyle $paraStyle, bool $isHeader): TableCell
    {
        $css = InlineStyleParser::parse($cell->getAttribute('style'));
        $attrs = $this->collectAttrs($cell);
        $cellStyle = CellStyleApplier::apply(new CellStyle, $css, $attrs);

        // The cell-level run and paragraph styles: the CSS applies to them too
        // (a colour or a font-size on a td spreads to the inner text).
        $cellRunStyle = RunStyleApplier::apply($runStyle, $css);
        $cellParaStyle = ParagraphStyleApplier::apply($paraStyle, $css);

        // A <th> is bold by default
        if ($isHeader) {
            $cellRunStyle = $cellRunStyle->withBold();
        }

        $blocks = $this->processChildNodes($cell, $cellRunStyle, $cellParaStyle);

        // When a cell is empty or holds only inlines that collapsed, we add an
        // empty paragraph — OOXML requires at least one <w:p> inside a <w:tc>.
        if ($blocks === []) {
            $blocks = [new Paragraph([], $cellParaStyle)];
        }

        return new TableCell($blocks, $cellStyle);
    }

    /**
     * The markup classes of the fields: `page-number` / `page-total`. Returns
     * `null` when the element has no such class.
     */
    private function fieldByClass(\DOMElement $node, RunStyle $style): ?Field
    {
        return match (true) {
            $this->hasClass($node, 'page-number') => Field::page($style),
            $this->hasClass($node, 'page-total') => Field::pageTotal($style),
            default => null,
        };
    }

    /**
     * A block element with a marker class: `page-break` gives a break,
     * `page-number`/`page-total` give a paragraph with a field. Null when there
     * is no such class.
     *
     * @return list<BlockElement>|null
     */
    private function blockByClass(\DOMElement $node, RunStyle $runStyle, ParagraphStyle $paraStyle): ?array
    {
        if ($this->hasClass($node, 'page-break')) {
            return [new PageBreak];
        }
        $field = $this->fieldByClass($node, $runStyle);

        return $field !== null ? [new Paragraph([$field], $paraStyle)] : null;
    }

    private function hasClass(\DOMElement $node, string $class): bool
    {
        $attr = strtolower($node->getAttribute('class'));

        return in_array($class, preg_split('/\s+/', trim($attr)) ?: [], true);
    }

    /**
     * @return list<BlockElement>
     */
    private function parseHr(\DOMElement $node): array
    {
        $class = strtolower($node->getAttribute('class'));
        if (in_array('page-break', preg_split('/\s+/', $class) ?: [], true)) {
            return [new PageBreak];
        }

        return [new HorizontalRule];
    }

    /**
     * @return list<BlockElement>
     */
    private function parseList(\DOMElement $node, RunStyle $runStyle, ParagraphStyle $paraStyle, bool $ordered): array
    {
        $type = $ordered ? ($node->getAttribute('type') ?: null) : null;
        $format = ListFormat::fromHtmlType($type, $ordered);
        $startAt = $ordered ? max(1, (int) ($node->getAttribute('start') ?: '1')) : 1;
        // A <li value="N"> on the first <li> is exactly equivalent to an
        // ol start="N". On the following <li> elements Word would require a
        // separate numbering instance, which is involved. We support the first
        // one only.
        $firstLi = $this->findFirstChild($node, 'li');
        if ($ordered && $firstLi !== null) {
            $val = $firstLi->getAttribute('value');
            if ($val !== '' && (int) $val > 0) {
                $startAt = (int) $val;
            }
        }

        $items = [];
        foreach ($node->childNodes as $child) {
            if (! $child instanceof \DOMElement) {
                continue;
            }
            if (strtolower($child->tagName) !== 'li') {
                continue;
            }
            $items[] = $this->parseListItem($child, $runStyle);
        }
        if ($items === []) {
            return [];
        }

        return [new ListNode($items, ordered: $ordered, format: $format, startAt: $startAt)];
    }

    private function findFirstChild(\DOMElement $node, string $tagName): ?\DOMElement
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement && strtolower($child->tagName) === $tagName) {
                return $child;
            }
        }

        return null;
    }

    private function parseListItem(\DOMElement $li, RunStyle $runStyle): ListItem
    {
        $inlines = [];
        $nestedList = null;
        foreach ($li->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $text = $this->normalizeText($child->textContent);
                if ($text !== '') {
                    $inlines[] = new Run($text, $runStyle);
                }

                continue;
            }
            if (! $child instanceof \DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if ($tag === 'ul' || $tag === 'ol') {
                // A nested list — we take the first one encountered. parseList
                // reads the type and the start of the nested <ol> itself.
                if ($nestedList === null) {
                    $produced = $this->parseList($child, $runStyle, new ParagraphStyle, ordered: $tag === 'ol');
                    if ($produced !== [] && $produced[0] instanceof ListNode) {
                        $nestedList = $produced[0];
                    }
                }

                continue;
            }
            foreach ($this->processInlineElement($child, $runStyle) as $i) {
                $inlines[] = $i;
            }
        }

        return new ListItem(children: $this->trimInline($inlines), nestedList: $nestedList);
    }

    private function parseInlineImage(\DOMElement $node): ?Image
    {
        $src = $node->getAttribute('src');
        if ($src === '' || ! str_starts_with($src, 'data:image/')) {
            return null;
        }
        if (preg_match('/^data:image\/(\w+);base64,(.+)$/s', $src, $m) !== 1) {
            return null;
        }
        $format = match (strtolower($m[1])) {
            'png' => ImageFormat::Png,
            'jpg', 'jpeg' => ImageFormat::Jpeg,
            'gif' => ImageFormat::Gif,
            'bmp' => ImageFormat::Bmp,
            default => null,
        };
        if ($format === null) {
            return null;
        }
        $binary = base64_decode($m[2], true);
        if ($binary === false) {
            return null;
        }

        // The CSS size wins over the attribute: it carries a unit. The
        // attribute does not (`width="96"` is always CSS pixels), so an author
        // who needs points cannot state them through the attribute.
        $css = InlineStyleParser::parse($node->getAttribute('style'));
        $widthPx = self::cssLengthToPx($css['width'] ?? null)
            ?? (int) ($node->getAttribute('width') ?: 0);
        $heightPx = self::cssLengthToPx($css['height'] ?? null)
            ?? (int) ($node->getAttribute('height') ?: 0);
        if ($widthPx <= 0 || $heightPx <= 0) {
            // With no dimensions we try to read them from the binary.
            $info = @getimagesizefromstring($binary);
            if ($info !== false) {
                $widthPx = $widthPx > 0 ? $widthPx : (int) $info[0];
                $heightPx = $heightPx > 0 ? $heightPx : (int) $info[1];
            }
        }
        $widthPx = max(1, $widthPx);
        $heightPx = max(1, $heightPx);

        return Image::fromPx(
            binary: $binary,
            format: $format,
            widthPx: $widthPx,
            heightPx: $heightPx,
            altText: $node->getAttribute('alt') ?: null,
        );
    }

    /**
     * A CSS length → CSS pixels (the unit Image::fromPx expects the size in).
     * Null for percentages, auto and rubbish.
     */
    private static function cssLengthToPx(?string $value): ?int
    {
        $twips = LengthParser::parseTwips($value);
        if ($twips === null || $twips <= 0) {
            return null;
        }

        // 1px = 15 twips (96 dpi).
        return max(1, (int) round($twips / 15));
    }

    private function loadHtml(string $html): \DOMDocument
    {
        $doc = new \DOMDocument;
        $wrapped = '<?xml encoding="UTF-8"?><html><body>'.$html.'</body></html>';
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML($wrapped, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        return $doc;
    }

    private function containsBlockChild(\DOMElement $node): bool
    {
        foreach ($node->childNodes as $child) {
            if (! $child instanceof \DOMElement) {
                continue;
            }
            if (in_array(strtolower($child->tagName), self::BLOCK_TAGS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    private function collectAttrs(\DOMElement $node): array
    {
        $out = [];
        foreach ($node->attributes as $attr) {
            if ($attr instanceof \DOMAttr) {
                $out[strtolower($attr->name)] = $attr->value;
            }
        }

        return $out;
    }

    private function normalizeText(string $text): string
    {
        // Collapse the whitespace (HTML style — several whitespace characters
        // become a single space). The tag-context preservation lives in
        // parsePreformatted (once <pre> is added).
        return (string) preg_replace('/\s+/u', ' ', $text);
    }

    /**
     * Removes the leading and trailing whitespace-only runs — Word collapses
     * "  Hello  " into "Hello" and we do the same, to avoid false indents.
     *
     * @param  list<InlineElement>  $inlines
     * @return list<InlineElement>
     */
    private function trimInline(array $inlines): array
    {
        // strip leading whitespace runs
        while ($inlines !== [] && $inlines[0] instanceof Run && trim($inlines[0]->text) === '') {
            array_shift($inlines);
        }
        // strip trailing whitespace runs
        while ($inlines !== [] && end($inlines) instanceof Run && trim(end($inlines)->text) === '') {
            array_pop($inlines);
        }

        return array_values($inlines);
    }
}
