<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Build;

use Dskripchenko\PhpDocx\Element\TableCell;
use Dskripchenko\PhpDocx\Element\TableRow;
use Dskripchenko\PhpDocx\Style\RowHeightRule;

/**
 * A fluent builder for a table row.
 *
 * Usage:
 *   $table->row(fn(TableRowBuilder $r) => $r
 *       ->cell('A')
 *       ->cell(fn(TableCellBuilder $c) => $c
 *           ->backgroundColor('eeeeee')
 *           ->paragraph('Rich')
 *       )
 *   )
 *
 * The short form (an array of strings) is supported at TableBuilder level.
 */
final class TableRowBuilder
{
    /** @var list<TableCell> */
    private array $cells = [];

    private bool $isHeader = false;

    private ?int $heightTwips = null;

    private bool $cantSplit = false;

    private RowHeightRule $heightRule = RowHeightRule::AtLeast;

    public function cell(string|callable $textOrBuilder): self
    {
        $cellBuilder = new TableCellBuilder;
        if (is_string($textOrBuilder)) {
            $cellBuilder->paragraph($textOrBuilder);
        } else {
            $textOrBuilder($cellBuilder);
        }
        $this->cells[] = $cellBuilder->build();

        return $this;
    }

    /**
     * Append pre-built TableCell.
     */
    public function addCell(TableCell $cell): self
    {
        $this->cells[] = $cell;

        return $this;
    }

    /**
     * A convenience for long rows: array<string>.
     *
     * @param  list<string>  $texts
     */
    public function cells(array $texts): self
    {
        foreach ($texts as $t) {
            $this->cell($t);
        }

        return $this;
    }

    public function header(bool $value = true): self
    {
        $this->isHeader = $value;

        return $this;
    }

    /**
     * A minimum row height in twips: the row grows with its content.
     */
    public function height(int $twips): self
    {
        $this->heightTwips = $twips;
        $this->heightRule = RowHeightRule::AtLeast;

        return $this;
    }

    /**
     * An exact row height in twips (`w:hRule="exact"`): content that does not
     * fit is clipped.
     */
    public function exactHeight(int $twips): self
    {
        $this->heightTwips = $twips;
        $this->heightRule = RowHeightRule::Exact;

        return $this;
    }

    public function cantSplit(bool $value = true): self
    {
        $this->cantSplit = $value;

        return $this;
    }

    public function build(): TableRow
    {
        return new TableRow($this->cells, $this->isHeader, $this->heightTwips, $this->cantSplit, $this->heightRule);
    }
}
