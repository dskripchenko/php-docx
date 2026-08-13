<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Build;

use Dskripchenko\PhpDocx\Element\TableCell;
use Dskripchenko\PhpDocx\Element\TableRow;

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

    public function height(int $twips): self
    {
        $this->heightTwips = $twips;

        return $this;
    }

    public function build(): TableRow
    {
        return new TableRow($this->cells, $this->isHeader, $this->heightTwips);
    }
}
