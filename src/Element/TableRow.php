<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Element;

/**
 * Строка таблицы — `<w:tr>`.
 */
final readonly class TableRow
{
    /**
     * @param  list<TableCell>  $cells
     * @param  bool  $isHeader  Если true — Word повторяет строку на каждой странице (`<w:tblHeader/>`).
     */
    public function __construct(
        public array $cells,
        public bool $isHeader = false,
        public ?int $heightTwips = null,
    ) {}
}
