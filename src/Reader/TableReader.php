<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Reader;

use Dskripchenko\PhpDocx\Element\BlockElement;
use Dskripchenko\PhpDocx\Element\Paragraph;
use Dskripchenko\PhpDocx\Element\Table;
use Dskripchenko\PhpDocx\Element\TableCell;
use Dskripchenko\PhpDocx\Element\TableRow;
use Dskripchenko\PhpDocx\Style\Alignment;
use Dskripchenko\PhpDocx\Style\BorderSet;
use Dskripchenko\PhpDocx\Style\CellStyle;
use Dskripchenko\PhpDocx\Style\TableStyle;
use Dskripchenko\PhpDocx\Style\VerticalAlign;

/**
 * Phase 4 — TableReader.
 *
 * `<w:tbl>` → Table:
 *  - `<w:tblPr>` → TableStyle (width, alignment, borders, layout, cellMargin)
 *  - `<w:tblGrid>/<w:gridCol>` → gridColumnsTwips
 *  - `<w:tr>` → TableRow (с trHeight, tblHeader)
 *  - `<w:tc>` → TableCell (gridSpan, vMerge restart/continue, width,
 *    borders, shading, padding, vAlign)
 *
 * vMerge reconstruction:
 *  - `<w:vMerge w:val="restart"/>` → начало merge-группы, считаем rowSpan
 *    путём сканирования последующих строк в той же колонке (по cumulative
 *    gridSpan-индексу)
 *  - `<w:vMerge/>` (без val) → continue-cell, **сохраняем** в AST с флагом
 *    vMergeContinue=true (writer тогда корректно повторно эмитит); HTML
 *    serializer (Phase 10) рассмотрит как «уже учтённую» и пропустит
 */
final class TableReader
{
    public function __construct(
        private readonly BodyReader $body = new BodyReader,
        private readonly OoxmlPropertyParser $props = new OoxmlPropertyParser,
    ) {}

    public function read(\DOMElement $tbl): Table
    {
        $tblPrEl = OoxmlNs::firstChild($tbl, OoxmlNs::W, 'tblPr');
        $tableStyle = $this->readTableStyle($tblPrEl);

        $gridColumns = $this->readGrid($tbl);

        // Caption — нечасто, OOXML hасто кладёт caption в paragraph над
        // таблицей через стиль "Caption" (Word UI). DOCX-схема также имеет
        // <w:caption> внутри <w:tbl> (редко). Пока не trying — caption=null;
        // Phase 10 при serialization может сшить ближайший Caption-paragraph.

        $rows = [];
        foreach (OoxmlNs::children($tbl, OoxmlNs::W, 'tr') as $trEl) {
            $rows[] = $this->readRow($trEl);
        }
        $rows = $this->reconstructRowSpans($rows);

        return new Table(
            rows: $rows,
            style: $tableStyle,
            caption: null,
            gridColumnsTwips: $gridColumns,
        );
    }

    /**
     * @return list<int>|null
     */
    private function readGrid(\DOMElement $tbl): ?array
    {
        $tblGrid = OoxmlNs::firstChild($tbl, OoxmlNs::W, 'tblGrid');
        if ($tblGrid === null) {
            return null;
        }
        $widths = [];
        foreach (OoxmlNs::children($tblGrid, OoxmlNs::W, 'gridCol') as $col) {
            // gridCol использует атрибут w:w (не w:val).
            if (! $col->hasAttributeNS(OoxmlNs::W, 'w')) {
                continue;
            }
            $w = $col->getAttributeNS(OoxmlNs::W, 'w');
            if (ctype_digit($w)) {
                $widths[] = (int) $w;
            }
        }

        return $widths === [] ? null : $widths;
    }

    private function readTableStyle(?\DOMElement $tblPr): TableStyle
    {
        if ($tblPr === null) {
            return new TableStyle;
        }

        $widthTwips = null;
        $widthPercent = null;
        $alignment = Alignment::Start;
        $fixedLayout = false;
        $borders = null;
        $cellMarTop = 0;
        $cellMarBottom = 0;
        $cellMarLeft = 80;
        $cellMarRight = 80;

        foreach ($tblPr->childNodes as $node) {
            if (! $node instanceof \DOMElement || $node->namespaceURI !== OoxmlNs::W) {
                continue;
            }
            switch ($node->localName) {
                case 'tblW':
                    [$widthTwips, $widthPercent] = $this->readWidthSpec($node);
                    break;
                case 'jc':
                    $alignment = match (OoxmlNs::wVal($node)) {
                        'center' => Alignment::Center,
                        'right', 'end' => Alignment::End,
                        default => Alignment::Start,
                    };
                    break;
                case 'tblLayout':
                    $fixedLayout = $node->getAttributeNS(OoxmlNs::W, 'type') === 'fixed';
                    break;
                case 'tblBorders':
                    $borders = $this->readTableBorders($node);
                    break;
                case 'tblCellMar':
                    [$cellMarTop, $cellMarRight, $cellMarBottom, $cellMarLeft] =
                        $this->readCellMar($node, $cellMarTop, $cellMarRight, $cellMarBottom, $cellMarLeft);
                    break;
            }
        }

        return new TableStyle(
            widthTwips: $widthTwips,
            widthPercent: $widthPercent,
            fixedLayout: $fixedLayout,
            borders: $borders,
            alignment: $alignment,
            cellMarginTopTwips: $cellMarTop,
            cellMarginRightTwips: $cellMarRight,
            cellMarginBottomTwips: $cellMarBottom,
            cellMarginLeftTwips: $cellMarLeft,
        );
    }

    private function readRow(\DOMElement $tr): TableRow
    {
        $trPr = OoxmlNs::firstChild($tr, OoxmlNs::W, 'trPr');
        $isHeader = false;
        $heightTwips = null;
        if ($trPr !== null) {
            $isHeader = OoxmlNs::firstChild($trPr, OoxmlNs::W, 'tblHeader') !== null;
            $h = OoxmlNs::firstChild($trPr, OoxmlNs::W, 'trHeight');
            if ($h !== null) {
                $v = OoxmlNs::wVal($h);
                if ($v !== null && ctype_digit($v)) {
                    $heightTwips = (int) $v;
                }
            }
        }

        $cells = [];
        foreach (OoxmlNs::children($tr, OoxmlNs::W, 'tc') as $tcEl) {
            $cells[] = $this->readCell($tcEl);
        }

        return new TableRow($cells, $isHeader, $heightTwips);
    }

    private function readCell(\DOMElement $tc): TableCell
    {
        $tcPr = OoxmlNs::firstChild($tc, OoxmlNs::W, 'tcPr');
        $style = $this->readCellStyle($tcPr);

        $children = [];
        foreach ($tc->childNodes as $child) {
            if (! $child instanceof \DOMElement || $child->namespaceURI !== OoxmlNs::W) {
                continue;
            }
            if ($child->localName === 'p') {
                foreach ($this->body->readParagraph($child) as $b) {
                    $children[] = $b;
                }
            } elseif ($child->localName === 'tbl') {
                $children[] = $this->read($child);
            }
            // tcPr — skip
        }
        if ($children === []) {
            $children = [new Paragraph([])];
        }

        return new TableCell($this->ensureBlockList($children), $style);
    }

    /**
     * @param  list<mixed>  $children
     * @return list<BlockElement>
     */
    private function ensureBlockList(array $children): array
    {
        return array_values(array_filter(
            $children,
            fn ($c): bool => $c instanceof BlockElement,
        ));
    }

    private function readCellStyle(?\DOMElement $tcPr): CellStyle
    {
        if ($tcPr === null) {
            return new CellStyle;
        }
        $widthTwips = null;
        $widthPercent = null;
        $padTop = 0;
        $padRight = 0;
        $padBottom = 0;
        $padLeft = 0;
        $valign = VerticalAlign::Top;
        $bg = null;
        $borders = null;
        $gridSpan = 1;
        $rowSpan = 1;
        $vMergeContinue = false;

        foreach ($tcPr->childNodes as $node) {
            if (! $node instanceof \DOMElement || $node->namespaceURI !== OoxmlNs::W) {
                continue;
            }
            switch ($node->localName) {
                case 'tcW':
                    [$widthTwips, $widthPercent] = $this->readWidthSpec($node);
                    break;
                case 'gridSpan':
                    $gridSpan = max(1, (int) (OoxmlNs::wVal($node) ?? '1'));
                    break;
                case 'vMerge':
                    $val = OoxmlNs::wVal($node);
                    if ($val === 'restart') {
                        // rowSpan вычислим позже через reconstructRowSpans
                        $rowSpan = 2; // временно, заменим
                    } else {
                        $vMergeContinue = true;
                    }
                    break;
                case 'tcBorders':
                    $borders = $this->readTcBorders($node);
                    break;
                case 'shd':
                    $bg = $this->readShdFill($node);
                    break;
                case 'tcMar':
                    [$padTop, $padRight, $padBottom, $padLeft] =
                        $this->readCellMar($node, $padTop, $padRight, $padBottom, $padLeft);
                    break;
                case 'vAlign':
                    $valign = match (OoxmlNs::wVal($node)) {
                        'center' => VerticalAlign::Center,
                        'bottom' => VerticalAlign::Bottom,
                        default => VerticalAlign::Top,
                    };
                    break;
            }
        }

        return new CellStyle(
            widthTwips: $widthTwips,
            widthPercent: $widthPercent,
            paddingTopTwips: $padTop,
            paddingRightTwips: $padRight,
            paddingBottomTwips: $padBottom,
            paddingLeftTwips: $padLeft,
            verticalAlign: $valign,
            backgroundColor: $bg,
            borders: $borders,
            gridSpan: $gridSpan,
            rowSpan: $rowSpan,
            vMergeContinue: $vMergeContinue,
        );
    }

    /**
     * Walk rows, для каждой vMergeContinue=true ячейки в строке N считаем
     * соответствующий restart-cell в строках 0..N-1 (по cumulative
     * gridSpan-индексу) и инкрементируем его rowSpan.
     *
     * @param  list<TableRow>  $rows
     * @return list<TableRow>
     */
    private function reconstructRowSpans(array $rows): array
    {
        if (count($rows) <= 1) {
            // ни одной continue быть не должно — возвращаем без модификаций,
            // только сбрасываем артефактные rowSpan=2 (от parseCell).
            return $this->resetTemporaryRowSpans($rows);
        }

        // Сначала — индексы restart-cells по cumulative column index.
        // openRestarts[colIndex] = [rowIdx, cellIdxInRow]
        $openRestarts = [];
        // Рабочий rowSpan для каждой restart-cell.
        // restartRowSpan[rowIdx][cellIdxInRow] = rowSpan
        $restartRowSpan = [];

        foreach ($rows as $rowIdx => $row) {
            $col = 0;
            foreach ($row->cells as $cellIdx => $cell) {
                $gs = max(1, $cell->style->gridSpan);
                if ($cell->style->vMergeContinue) {
                    // Найти open restart на этом col-индексе → +1 rowSpan
                    if (isset($openRestarts[$col])) {
                        [$rIdx, $cIdx] = $openRestarts[$col];
                        $restartRowSpan[$rIdx][$cIdx] = ($restartRowSpan[$rIdx][$cIdx] ?? 1) + 1;
                    }
                    $col += $gs;

                    continue;
                }
                // обычная или restart-cell
                $isRestart = $cell->style->rowSpan > 1; // сигнал из readCellStyle
                if ($isRestart) {
                    $openRestarts[$col] = [$rowIdx, $cellIdx];
                    $restartRowSpan[$rowIdx][$cellIdx] = 1;
                }
                $col += $gs;
            }
        }

        // Применяем computed rowSpan'ы.
        $newRows = [];
        foreach ($rows as $rowIdx => $row) {
            $newCells = [];
            foreach ($row->cells as $cellIdx => $cell) {
                $newCells[] = $this->cellWithRowSpan(
                    $cell,
                    $restartRowSpan[$rowIdx][$cellIdx] ?? ($cell->style->vMergeContinue ? 1 : 1),
                );
            }
            $newRows[] = new TableRow($newCells, $row->isHeader, $row->heightTwips);
        }

        return $newRows;
    }

    private function cellWithRowSpan(TableCell $cell, int $rowSpan): TableCell
    {
        if ($cell->style->rowSpan === $rowSpan) {
            return $cell;
        }
        $s = $cell->style;

        return new TableCell(
            children: $cell->children,
            style: new CellStyle(
                widthTwips: $s->widthTwips,
                widthPercent: $s->widthPercent,
                paddingTopTwips: $s->paddingTopTwips,
                paddingRightTwips: $s->paddingRightTwips,
                paddingBottomTwips: $s->paddingBottomTwips,
                paddingLeftTwips: $s->paddingLeftTwips,
                verticalAlign: $s->verticalAlign,
                backgroundColor: $s->backgroundColor,
                borders: $s->borders,
                gridSpan: $s->gridSpan,
                rowSpan: $rowSpan,
                vMergeContinue: $s->vMergeContinue,
            ),
        );
    }

    /**
     * @param  list<TableRow>  $rows
     * @return list<TableRow>
     */
    private function resetTemporaryRowSpans(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $newCells = [];
            foreach ($row->cells as $cell) {
                if ($cell->style->rowSpan !== 1 && ! $cell->style->vMergeContinue) {
                    $newCells[] = $this->cellWithRowSpan($cell, 1);

                    continue;
                }
                $newCells[] = $cell;
            }
            $out[] = new TableRow($newCells, $row->isHeader, $row->heightTwips);
        }

        return $out;
    }

    /**
     * @return array{0: ?int, 1: ?int}
     */
    private function readWidthSpec(\DOMElement $w): array
    {
        $type = $w->getAttributeNS(OoxmlNs::W, 'type');
        $val = $w->getAttributeNS(OoxmlNs::W, 'w');
        if (! ctype_digit($val) || (int) $val === 0) {
            return [null, null];
        }
        $int = (int) $val;
        if ($type === 'pct') {
            // В OOXML значение % хранится либо как N*50 (5000 = 100%),
            // либо как "20.00%" (string). Мы поддерживаем оба варианта.
            return [null, $int];
        }
        // dxa / auto / nil
        return [$int, null];
    }

    /**
     * @return array{0:int,1:int,2:int,3:int} top,right,bottom,left
     */
    private function readCellMar(\DOMElement $mar, int $top, int $right, int $bottom, int $left): array
    {
        foreach (['top' => &$top, 'right' => &$right, 'bottom' => &$bottom, 'left' => &$left] as $side => &$ref) {
            $node = OoxmlNs::firstChild($mar, OoxmlNs::W, $side);
            if ($node !== null && $node->hasAttributeNS(OoxmlNs::W, 'w')) {
                $v = $node->getAttributeNS(OoxmlNs::W, 'w');
                if (ctype_digit($v)) {
                    $ref = (int) $v;
                }
            }
        }

        return [$top, $right, $bottom, $left];
    }

    private function readShdFill(\DOMElement $shd): ?string
    {
        if (! $shd->hasAttributeNS(OoxmlNs::W, 'fill')) {
            return null;
        }
        $fill = $shd->getAttributeNS(OoxmlNs::W, 'fill');
        if ($fill === 'auto' || $fill === '' || ! preg_match('/^[0-9A-Fa-f]{6}$/', $fill)) {
            return null;
        }

        return strtolower($fill);
    }

    private function readTableBorders(\DOMElement $tblBorders): BorderSet
    {
        return $this->props->parseBorders($tblBorders);
    }

    private function readTcBorders(\DOMElement $tcBorders): BorderSet
    {
        return $this->props->parseBorders($tcBorders);
    }
}
