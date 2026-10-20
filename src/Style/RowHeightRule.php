<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Style;

/**
 * How a table row's height is applied (`<w:trHeight w:hRule="..."/>`).
 */
enum RowHeightRule: string
{
    /** The row is at least this high and grows with its content. */
    case AtLeast = 'atLeast';

    /** The row is exactly this high; content that does not fit is clipped. */
    case Exact = 'exact';
}
