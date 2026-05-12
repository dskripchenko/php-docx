<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Element;

use Dskripchenko\PhpDocx\Style\CellStyle;

/**
 * Ячейка таблицы — `<w:tc>`.
 * Содержит block-elements (несколько `<w:p>` для multi-paragraph cells).
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
