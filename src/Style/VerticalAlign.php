<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Style;

/**
 * Вертикальное выравнивание ячейки (`<w:vAlign w:val="..."/>`).
 */
enum VerticalAlign: string
{
    case Top = 'top';
    case Center = 'center';
    case Bottom = 'bottom';
}
