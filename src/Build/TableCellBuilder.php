<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Build;

use Dskripchenko\PhpDocx\Element\Paragraph;
use Dskripchenko\PhpDocx\Element\TableCell;
use Dskripchenko\PhpDocx\Style\BorderSet;
use Dskripchenko\PhpDocx\Style\CellStyle;
use Dskripchenko\PhpDocx\Style\VerticalAlign;

/**
 * A fluent builder for a single table cell. Any block
 * (paragraph/heading/table/list/image) can go inside a cell through the
 * AddsBlockContent trait — cells are full-blown section containers.
 *
 * Used as `$row->cell(fn(TableCellBuilder $c) => $c->paragraph('A'))`, or in
 * the short form `$row->cell('A')` (which makes a cell with one paragraph).
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

    public function widthPt(float $pt): self
    {
        return $this->widthTwips(Length::pt($pt));
    }

    public function widthMm(float $mm): self
    {
        return $this->widthTwips(Length::mm($mm));
    }

    public function widthCm(float $cm): self
    {
        return $this->widthTwips(Length::cm($cm));
    }

    public function widthInches(float $inches): self
    {
        return $this->widthTwips(Length::inch($inches));
    }

    /**
     * The width as a percentage. 0..100 is the convenience form (multiplied by
     * 50 internally to get the OOXML-friendly pct=N*50 value).
     */
    public function widthPercent(int $percent): self
    {
        $this->widthPercent = max(0, min(100, $percent)) * 50;
        $this->widthTwips = null;

        return $this;
    }

    /**
     * The padding of every side in twips. A single value is applied to all
     * four sides.
     */
    public function padding(int $top, ?int $right = null, ?int $bottom = null, ?int $left = null): self
    {
        $this->paddingTopTwips = $top;
        $this->paddingRightTwips = $right ?? $top;
        $this->paddingBottomTwips = $bottom ?? $top;
        $this->paddingLeftTwips = $left ?? $right ?? $top;

        return $this;
    }

    public function paddingMm(float $top, ?float $right = null, ?float $bottom = null, ?float $left = null): self
    {
        return $this->padding(
            Length::mm($top),
            $right === null ? null : Length::mm($right),
            $bottom === null ? null : Length::mm($bottom),
            $left === null ? null : Length::mm($left),
        );
    }

    public function paddingPt(float $top, ?float $right = null, ?float $bottom = null, ?float $left = null): self
    {
        return $this->padding(
            Length::pt($top),
            $right === null ? null : Length::pt($right),
            $bottom === null ? null : Length::pt($bottom),
            $left === null ? null : Length::pt($left),
        );
    }

    public function paddingCm(float $top, ?float $right = null, ?float $bottom = null, ?float $left = null): self
    {
        return $this->padding(
            Length::cm($top),
            $right === null ? null : Length::cm($right),
            $bottom === null ? null : Length::cm($bottom),
            $left === null ? null : Length::cm($left),
        );
    }

    public function paddingInches(float $top, ?float $right = null, ?float $bottom = null, ?float $left = null): self
    {
        return $this->padding(
            Length::inch($top),
            $right === null ? null : Length::inch($right),
            $bottom === null ? null : Length::inch($bottom),
            $left === null ? null : Length::inch($left),
        );
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
            // The OOXML schema requires at least one <w:p> inside <w:tc>.
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
