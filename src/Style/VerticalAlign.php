<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Style;

/**
 * The vertical alignment of a cell (`<w:vAlign w:val="..."/>`).
 */
enum VerticalAlign: string
{
    case Top = 'top';
    case Center = 'center';
    case Bottom = 'bottom';
}
