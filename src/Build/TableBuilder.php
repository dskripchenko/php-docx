<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Build;

use Dskripchenko\PhpDocx\Element\Table;
use Dskripchenko\PhpDocx\Element\TableRow;
use Dskripchenko\PhpDocx\Style\Alignment;
use Dskripchenko\PhpDocx\Style\BorderSet;
use Dskripchenko\PhpDocx\Style\TableStyle;

/**
 * A fluent builder for a table.
 *
 * Usage:
 *   $doc->table(fn(TableBuilder $t) => $t
 *       ->columns(2000, 1500, 1500)   // gridColumnsTwips
 *       ->caption('Sales 2026')
 *       ->headerRow(['Item', 'Qty', 'Price'])
 *       ->row(['Apple', '1', '10$'])
 *       ->row(fn(TableRowBuilder $r) => $r
 *           ->cell('Banana')
 *           ->cell('2')
 *           ->cell(fn(TableCellBuilder $c) => $c
 *               ->backgroundColor('ffeb3b')
 *               ->paragraph('20$')
 *           )
 *       )
 *   )
 */
final class TableBuilder
{
    /** @var list<TableRow> */
    private array $rows = [];

    private ?string $caption = null;

    /** @var list<int>|null */
    private ?array $gridColumnsTwips = null;

    private ?int $widthTwips = null;

    private ?int $widthPercent = null;

    private bool $fixedLayout = false;

    private Alignment $alignment = Alignment::Start;

    private ?BorderSet $borders = null;

    private int $cellMarginTopTwips = 0;

    private int $cellMarginRightTwips = 80;

    private int $cellMarginBottomTwips = 0;

    private int $cellMarginLeftTwips = 80;

    /**
     * A header row — the short form with a list of strings, or the long one
     * with a RowBuilder.
     *
     * @param  list<string>|callable(TableRowBuilder): void  $textsOrBuilder
     */
    public function headerRow(array|callable $textsOrBuilder): self
    {
        $row = new TableRowBuilder;
        $row->header(true);
        if (is_array($textsOrBuilder)) {
            $row->cells($textsOrBuilder);
        } else {
            $textsOrBuilder($row);
            $row->header(true); // re-assert на случай если callback сбросил
        }
        $this->rows[] = $row->build();

        return $this;
    }

    /**
     * An ordinary row. The short form is an array<string>, the long one a
     * closure.
     *
     * @param  list<string>|callable(TableRowBuilder): void  $textsOrBuilder
     */
    public function row(array|callable $textsOrBuilder): self
    {
        $row = new TableRowBuilder;
        if (is_array($textsOrBuilder)) {
            $row->cells($textsOrBuilder);
        } else {
            $textsOrBuilder($row);
        }
        $this->rows[] = $row->build();

        return $this;
    }

    public function addRow(TableRow $row): self
    {
        $this->rows[] = $row;

        return $this;
    }

    public function caption(string $text): self
    {
        $this->caption = $text;

        return $this;
    }

    /**
     * Explicit grid columns (for `<colgroup>`-like control over the widths).
     *
     * It replaces EVERY column declared before. To append a single one, see
     * `column()`.
     */
    public function columns(int ...$widthsTwips): self
    {
        $this->gridColumnsTwips = array_values($widthsTwips);

        return $this;
    }

    /**
     * Appends a single column through a ColumnBuilder. It allows
     * widthMm/widthCm/widthPt/widthInches/widthPx instead of doing the maths by
     * hand.
     *
     * @param  callable(ColumnBuilder): void  $builderCallback
     */
    public function column(callable $builderCallback): self
    {
        $col = new ColumnBuilder;
        $builderCallback($col);
        $this->gridColumnsTwips ??= [];
        $this->gridColumnsTwips[] = $col->build();

        return $this;
    }

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
     * 0..100 → multiplied by 50 internally for the OOXML pct.
     */
    public function widthPercent(int $percent): self
    {
        $this->widthPercent = max(0, min(100, $percent)) * 50;
        $this->widthTwips = null;

        return $this;
    }

    public function fixedLayout(bool $value = true): self
    {
        $this->fixedLayout = $value;

        return $this;
    }

    public function alignment(Alignment $alignment): self
    {
        $this->alignment = $alignment;

        return $this;
    }

    public function alignCenter(): self
    {
        return $this->alignment(Alignment::Center);
    }

    public function borders(BorderSet $borders): self
    {
        $this->borders = $borders;

        return $this;
    }

    public function cellMargins(int $top, ?int $right = null, ?int $bottom = null, ?int $left = null): self
    {
        $this->cellMarginTopTwips = $top;
        $this->cellMarginRightTwips = $right ?? $top;
        $this->cellMarginBottomTwips = $bottom ?? $top;
        $this->cellMarginLeftTwips = $left ?? $right ?? $top;

        return $this;
    }

    public function cellMarginsMm(float $top, ?float $right = null, ?float $bottom = null, ?float $left = null): self
    {
        return $this->cellMargins(
            Length::mm($top),
            $right === null ? null : Length::mm($right),
            $bottom === null ? null : Length::mm($bottom),
            $left === null ? null : Length::mm($left),
        );
    }

    public function cellMarginsPt(float $top, ?float $right = null, ?float $bottom = null, ?float $left = null): self
    {
        return $this->cellMargins(
            Length::pt($top),
            $right === null ? null : Length::pt($right),
            $bottom === null ? null : Length::pt($bottom),
            $left === null ? null : Length::pt($left),
        );
    }

    public function build(): Table
    {
        return new Table(
            rows: $this->rows,
            style: new TableStyle(
                widthTwips: $this->widthTwips,
                widthPercent: $this->widthPercent,
                fixedLayout: $this->fixedLayout,
                borders: $this->borders,
                alignment: $this->alignment,
                cellMarginTopTwips: $this->cellMarginTopTwips,
                cellMarginRightTwips: $this->cellMarginRightTwips,
                cellMarginBottomTwips: $this->cellMarginBottomTwips,
                cellMarginLeftTwips: $this->cellMarginLeftTwips,
            ),
            caption: $this->caption,
            gridColumnsTwips: $this->gridColumnsTwips,
        );
    }
}
