<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Element;

use Dskripchenko\PhpDocx\Style\TableStyle;

/**
 * A table — `<w:tbl>`.
 *
 * The gridCols are computed automatically from the cell widths of the first
 * row, unless an explicit `$gridColumnsTwips` is passed in (from `<colgroup>`,
 * for instance).
 *
 * When the cells carry no explicit width, all the columns share the space
 * equally.
 *
 * `$caption` is the optional caption text, emitted as a separate paragraph
 * before the table with the Caption style.
 */
final readonly class Table implements BlockElement
{
    /**
     * @param  list<TableRow>  $rows
     * @param  list<int>|null  $gridColumnsTwips  An override for <w:tblGrid> (from <colgroup>)
     */
    public function __construct(
        public array $rows,
        public TableStyle $style = new TableStyle,
        public ?string $caption = null,
        public ?array $gridColumnsTwips = null,
    ) {}
}
