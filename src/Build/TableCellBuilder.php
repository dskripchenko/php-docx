<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Build;

use Dskripchenko\PhpDocx\Element\Paragraph;
use Dskripchenko\PhpDocx\Element\TableCell;
use Dskripchenko\PhpDocx\Style\BorderSet;
use Dskripchenko\PhpDocx\Style\CellStyle;
use Dskripchenko\PhpDocx\Style\VerticalAlign;

/**
 * Fluent builder для одной ячейки таблицы. Внутри ячейки можно класть
 * любые блоки (paragraph/heading/table/list/image) через trait
 * AddsBlockContent — ячейки — это полноценные section-containers.
 *
 * Используется как `$row->cell(fn(TableCellBuilder $c) => $c->paragraph('A'))`
 * или короткой формой `$row->cell('A')` (создаёт ячейку с одним paragraph'ом).
 */
final class TableCellBuilder
{
    use AddsBlockContent;

    private ?int $widthTwips = null;

    private ?int $widthPercent = null;

    private int $paddingTopTwips = 0;

    private int $paddingRightTwips = 0;

    private int $paddingBottomTwips = 0;

    private int $paddingLeftTwips = 0;

    private VerticalAlign $verticalAlign = VerticalAlign::Top;

    private ?string $backgroundColor = null;

    private ?BorderSet $borders = null;

    private int $gridSpan = 1;

    private int $rowSpan = 1;

    public function widthTwips(int $twips): self
    {
        $this->widthTwips = $twips;
        $this->widthPercent = null;

        return $this;
    }

    /**
     * Ширина в процентах. 0..100 — convenience-форма (внутренне умножается
     * на 50 чтобы получить OOXML-friendly значение pct=N*50).
     */
    public function widthPercent(int $percent): self
    {
        $this->widthPercent = max(0, min(100, $percent)) * 50;
        $this->widthTwips = null;

        return $this;
    }

    /**
     * Padding всех сторон в twips. Если передано 1 значение — применяется
     * ко всем сторонам.
     */
    public function padding(int $top, ?int $right = null, ?int $bottom = null, ?int $left = null): self
    {
        $this->paddingTopTwips = $top;
        $this->paddingRightTwips = $right ?? $top;
        $this->paddingBottomTwips = $bottom ?? $top;
        $this->paddingLeftTwips = $left ?? $right ?? $top;

        return $this;
    }

    public function backgroundColor(string $hexWithoutHash): self
    {
        $this->backgroundColor = strtolower(ltrim($hexWithoutHash, '#'));

        return $this;
    }

    public function verticalAlign(VerticalAlign $align): self
    {
        $this->verticalAlign = $align;

        return $this;
    }

    public function valignCenter(): self
    {
        return $this->verticalAlign(VerticalAlign::Center);
    }

    public function valignBottom(): self
    {
        return $this->verticalAlign(VerticalAlign::Bottom);
    }

    public function borders(BorderSet $borders): self
    {
        $this->borders = $borders;

        return $this;
    }

    public function gridSpan(int $colspan): self
    {
        $this->gridSpan = max(1, $colspan);

        return $this;
    }

    public function rowSpan(int $rowspan): self
    {
        $this->rowSpan = max(1, $rowspan);

        return $this;
    }

    public function build(): TableCell
    {
        $children = $this->buildBlocks();
        if ($children === []) {
            // OOXML schema требует хотя бы один <w:p> в <w:tc>.
            $children = [new Paragraph([])];
        }

        return new TableCell(
            children: $children,
            style: new CellStyle(
                widthTwips: $this->widthTwips,
                widthPercent: $this->widthPercent,
                paddingTopTwips: $this->paddingTopTwips,
                paddingRightTwips: $this->paddingRightTwips,
                paddingBottomTwips: $this->paddingBottomTwips,
                paddingLeftTwips: $this->paddingLeftTwips,
                verticalAlign: $this->verticalAlign,
                backgroundColor: $this->backgroundColor,
                borders: $this->borders,
                gridSpan: $this->gridSpan,
                rowSpan: $this->rowSpan,
            ),
        );
    }
}
