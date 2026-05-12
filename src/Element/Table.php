<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Element;

use Dskripchenko\PhpDocx\Style\TableStyle;

/**
 * Таблица — `<w:tbl>`.
 *
 * gridCols вычисляются автоматически по cell widths первого ряда.
 * Если cells не имеют explicit width — все колонки делятся поровну.
 */
final readonly class Table implements BlockElement
{
    /**
     * @param  list<TableRow>  $rows
     */
    public function __construct(
        public array $rows,
        public TableStyle $style = new TableStyle,
    ) {}
}
