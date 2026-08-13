<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Element;

/**
 * A table row — `<w:tr>`.
 */
final readonly class TableRow
{
    /**
     * @param  list<TableCell>  $cells
     * @param  bool  $isHeader  When true, Word repeats the row on every page (`<w:tblHeader/>`).
     */
    public function __construct(
        public array $cells,
        public bool $isHeader = false,
        public ?int $heightTwips = null,
    ) {}
}
