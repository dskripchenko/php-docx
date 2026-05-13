<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Build;

use Dskripchenko\PhpDocx\Element\Table;
use Dskripchenko\PhpDocx\Element\TableRow;
use Dskripchenko\PhpDocx\Style\Alignment;
use Dskripchenko\PhpDocx\Style\BorderSet;
use Dskripchenko\PhpDocx\Style\TableStyle;

/**
 * Fluent builder для таблицы.
 *
 * Использование:
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
     * Header-row — короткая форма со списком строк или длинная с RowBuilder.
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
     * Обычная строка. Короткая — array<string>; длинная — closure.
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
     * Explicit grid columns (для <colgroup>-like контроля над шириной).
     */
    public function columns(int ...$widthsTwips): self
    {
        $this->gridColumnsTwips = array_values($widthsTwips);

        return $this;
    }

    public function widthTwips(int $twips): self
    {
        $this->widthTwips = $twips;
        $this->widthPercent = null;

        return $this;
    }

    /**
     * 0..100 → внутренне умножается на 50 для OOXML pct.
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
