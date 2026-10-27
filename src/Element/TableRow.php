<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Element;

use Dskripchenko\PhpDocx\Style\RowHeightRule;

/**
 * A table row — `<w:tr>`.
 */
final readonly class TableRow
{
    /**
     * @param  list<TableCell>  $cells
     * @param  bool  $isHeader  When true, Word repeats the row on every page (`<w:tblHeader/>`).
     * @param  bool  $cantSplit  When true, the row must not break across pages (`<w:cantSplit/>`; Word's "Allow row to break across pages" turned off).
     * @param  RowHeightRule  $heightRule  How `$heightTwips` applies (`w:hRule`): a minimum (default) or an exact height.
     */
    public function __construct(
        public array $cells,
        public bool $isHeader = false,
        public ?int $heightTwips = null,
        public bool $cantSplit = false,
        public RowHeightRule $heightRule = RowHeightRule::AtLeast,
    ) {}
}
