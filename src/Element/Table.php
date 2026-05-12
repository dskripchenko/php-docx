<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Element;

use Dskripchenko\PhpDocx\Style\TableStyle;

/**
 * Таблица — `<w:tbl>`.
 *
 * gridCols вычисляются автоматически по cell widths первого ряда —
 * если не передан explicit `$gridColumnsTwips` (например, из <colgroup>).
 *
 * Если cells не имеют explicit width — все колонки делятся поровну.
 *
 * `$caption` — опциональный текст подписи, эмитится отдельным paragraph'ом
 * перед таблицей со стилем Caption.
 */
final readonly class Table implements BlockElement
{
    /**
     * @param  list<TableRow>  $rows
     * @param  list<int>|null  $gridColumnsTwips  Override для <w:tblGrid> (из <colgroup>)
     */
    public function __construct(
        public array $rows,
        public TableStyle $style = new TableStyle,
        public ?string $caption = null,
        public ?array $gridColumnsTwips = null,
    ) {}
}
