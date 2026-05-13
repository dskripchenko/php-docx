<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Build;

use Dskripchenko\PhpDocx\Element\TableCell;
use Dskripchenko\PhpDocx\Element\TableRow;

/**
 * Fluent builder для строки таблицы.
 *
 * Использование:
 *   $table->row(fn(TableRowBuilder $r) => $r
 *       ->cell('A')
 *       ->cell(fn(TableCellBuilder $c) => $c
 *           ->backgroundColor('eeeeee')
 *           ->paragraph('Rich')
 *       )
 *   )
 *
 * Короткая форма (string-array) поддерживается на уровне TableBuilder.
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
     * Convenience для длинных рядов: array<string>.
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
