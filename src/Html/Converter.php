<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Html;

use Dskripchenko\PhpDocx\Document;
use Dskripchenko\PhpDocx\Element\BlockElement;
use Dskripchenko\PhpDocx\Element\Field;
use Dskripchenko\PhpDocx\Element\HorizontalRule;
use Dskripchenko\PhpDocx\Element\Hyperlink;
use Dskripchenko\PhpDocx\Element\Image;
use Dskripchenko\PhpDocx\Element\ImageFormat;
use Dskripchenko\PhpDocx\Element\InlineElement;
use Dskripchenko\PhpDocx\Element\LineBreak;
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
 * Парсит HTML5 через `DOMDocument::loadHTML` (lenient mode), обходит дерево,
 * мапит элементы в типизированные value-objects.
 *
 *  - Только inline `style="..."` для стилей; CSS-rules — caller-inlines upstream.
 *  - Картинки: только `<img src="data:image/...;base64,...">`. URL-images
 *    (`http://`/`file://`) игнорируются (caller должен resolve'ить upstream).
 *  - Block vs inline разруливается по тегу в `BLOCK_TAGS` set'е.
 */
final class Converter
{
    /** Теги, которые превращаются в BlockElement или производят их split. */
    private const array BLOCK_TAGS = ['p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'table', 'hr', 'ul', 'ol', 'blockquote', 'pagebreak', 'pre', 'dl', 'figure', 'section', 'article', 'aside', 'header', 'footer', 'nav', 'main'];

    public function __construct(
        private readonly PageSetup $defaultPageSetup = new PageSetup,
    ) {}

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

        // Body's inline-style — basis для всех children. Симулируем CSS
        // inheritance: font-family/color/font-size с <body> применяются ко
        // всем descendants. CssInliner (tijsverkoyen) НЕ propagates
        // inherited properties down — без этого все runs без явного fonts.
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

        return match ($tag) {
            'p', 'div' => $this->parseParagraph($node, $localRun, $localPara, headingLevel: null),
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6' =>
                $this->parseParagraph($node, $localRun, $localPara, headingLevel: (int) substr($tag, 1)),
            'table' => [$this->parseTable($node, $localRun, $localPara)],
            'hr' => $this->parseHr($node),
            'pagebreak' => [new PageBreak],
            'ul', 'ol' => $this->parseList($node, $localRun, $localPara, ordered: $tag === 'ol'),
            'blockquote' => $this->processChildNodes($node, $localRun, $localPara->copy(indentLeftTwips: 720)),
            // Semantic block-теги — рендерим как div'ы (children).
            'section', 'article', 'aside', 'header', 'footer', 'nav', 'main' =>
                $this->processChildNodes($node, $localRun, $localPara),
            // Pre — preserve whitespace + Courier New. Текст внутри идёт как-есть.
            'pre' => $this->parsePreformatted($node, $localRun->withFontFamily('Courier New'), $localPara),
            default => [],
        };
    }

    /**
     * @return list<BlockElement>
     */
    private function parsePreformatted(\DOMElement $node, RunStyle $runStyle, ParagraphStyle $paraStyle): array
    {
        // Preserve whitespace и newlines. Внутренние <code>/<br>/etc parse'ятся
        // как inline, но не normalize'им text content.
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
        // Если внутри блочного <p>/<div>/<h*> есть только inline-children —
        // собираем единый Paragraph. Если есть block-level дети (вложенный
        // <table>, <ul> и т.д.) — рекурсируем и параграф не создаём.
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

        // Mark-теги наследуют + добавляют свой признак (через with*-helpers).
        $marked = match ($tag) {
            'strong', 'b' => $local->withBold(),
            'em', 'i', 'cite', 'dfn', 'var', 'address' => $local->withItalic(),
            'u' => $local->withUnderline(),
            's', 'del', 'strike' => $local->withStrikethrough(),
            'sup' => $local->withSuperscript(),
            'sub' => $local->withSubscript(),
            // Highlighted text (CSS background-color на span НЕ работает в OOXML).
            'mark' => $local->withHighlight('yellow'),
            // Monospaced inline-теги.
            'code', 'kbd', 'samp', 'tt' => $local->withFontFamily('Courier New'),
            // Smaller font (≈83% от текущего).
            'small' => $local->withSizeHalfPoints(
                $local->sizeHalfPoints !== null
                    ? max(10, (int) round($local->sizeHalfPoints * 0.83))
                    : 18,
            ),
            // Inline quoted (italic + добавим « » если хочется — пока просто italic).
            'q' => $local->withItalic(),
            default => $local,
        };

        return match ($tag) {
            'br' => [new LineBreak],
            'img' => $this->parseInlineImage($node) !== null ? [$this->parseInlineImage($node)] : [],
            'a' => [new Hyperlink(
                href: $node->getAttribute('href'),
                children: $this->collectInline($node, $marked),
            )],
            // Custom-теги для field codes:
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
            // Mark-теги + неизвестные inline-теги (span и т.д.) — просто
            // прокидываем стиль в детей.
            default => $this->collectInline($node, $marked),
        };
    }

    /**
     * Рекурсивный сбор inline-children с применённым style.
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

        // <caption> текст (опционально) — render'ится перед <w:tbl> со стилем Caption.
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
        // Rowspan: автоматически inject'им continue-cells (<w:vMerge/>)
        // в следующие строки для каждой ячейки с rowSpan>1.
        $rows = $this->expandRowSpans($rows);

        return new Table($rows, $tableStyle, $caption, $gridColumnsTwips);
    }

    /**
     * Walk rows, для каждой rowSpan>1 ячейки добавляет matching continue-cells
     * в следующие row'ы. Иначе OOXML rowspan не работает: w:vMerge ожидается
     * в КАЖДОЙ строке, которую затрагивает merge.
     *
     * @param  list<TableRow>  $rows
     * @return list<TableRow>
     */
    private function expandRowSpans(array $rows): array
    {
        if (count($rows) <= 1) {
            return $rows;
        }

        // For each row produce expanded cell list.
        // pendingByCol[colIndex] = remaining_continuations
        // continueStyle[colIndex] = CellStyle для continue-cell (наследуем
        //   width/gridSpan/borders от originating cell для column-alignment).
        $pendingByCol = [];
        $continueStyle = [];

        $newRows = [];
        foreach ($rows as $row) {
            $newCells = [];
            $colCursor = 0;
            $origIdx = 0;

            // Step через колонки, вставляя continue-cells на pending-позициях
            // и копируя original cells в остальные.
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
        // Default для col'ов без width — равное распределение остатка от 9000 twips.
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
     * Распределяет недостающие cell widths в первой строке до 100% (5000 pct).
     * Используется как gridCol-источник, поэтому первая строка решает.
     */
    private function balanceFirstRowWidths(TableRow $row): TableRow
    {
        if ($row->cells === []) {
            return $row;
        }

        // Считаем что имеем; 5000 pct = 100%; 100mm = ~5670 twips.
        $totalPctClaimed = 0;
        $cellsWithoutWidth = [];
        foreach ($row->cells as $i => $cell) {
            if ($cell->style->widthPercent !== null) {
                $totalPctClaimed += $cell->style->widthPercent;
            } elseif ($cell->style->widthTwips !== null) {
                // ничего: explicit twips — не трогаем
            } else {
                $cellsWithoutWidth[] = $i;
            }
        }

        if ($cellsWithoutWidth === []) {
            return $row;
        }

        // Доступный остаток в pct.
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

        // Cell-уровневые run/paragraph стили: применяем CSS к ним тоже
        // (color/font-size на td → distributes to inner text).
        $cellRunStyle = RunStyleApplier::apply($runStyle, $css);
        $cellParaStyle = ParagraphStyleApplier::apply($paraStyle, $css);

        // <th> по умолчанию bold
        if ($isHeader) {
            $cellRunStyle = $cellRunStyle->withBold();
        }

        $blocks = $this->processChildNodes($cell, $cellRunStyle, $cellParaStyle);

        // Если ячейка пустая или содержит только inline'ы которые collapse'нули,
        // добавим пустой параграф — OOXML требует хотя бы один <w:p> в <w:tc>.
        if ($blocks === []) {
            $blocks = [new Paragraph([], $cellParaStyle)];
        }

        return new TableCell($blocks, $cellStyle);
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

        return [new ListNode($items, ordered: $ordered)];
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
                // Nested list — берём первый encountered.
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

        $widthPx = (int) ($node->getAttribute('width') ?: 0);
        $heightPx = (int) ($node->getAttribute('height') ?: 0);
        if ($widthPx <= 0 || $heightPx <= 0) {
            // Без размеров — пытаемся прочитать из binary.
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
        // Collapse whitespace (HTML-style — multiple WS → single space).
        // Tags-context preserve будет в parsePreformatted (когда добавим <pre>).
        return (string) preg_replace('/\s+/u', ' ', $text);
    }

    /**
     * Убирает leading/trailing whitespace-only Run'ы — Word склеивает
     * "  Hello  " в "Hello", мы делаем то же чтобы избежать ложных
     * indent'ов.
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
