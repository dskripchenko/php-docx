<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Element;

use Dskripchenko\PhpDocx\Style\CellStyle;

/**
 * A table cell — `<w:tc>`.
 * It holds block elements (several `<w:p>` for multi-paragraph cells).
 */
final readonly class TableCell
{
    /**
     * @param  list<BlockElement>  $children
     */
    public function __construct(
        public array $children,
        public CellStyle $style = new CellStyle,
    ) {}
}
