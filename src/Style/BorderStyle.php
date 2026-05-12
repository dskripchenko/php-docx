<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Style;

/**
 * Стиль линии бордера. Значения соответствуют `<w:val>` атрибуту
 * в OOXML border-элементах.
 */
enum BorderStyle: string
{
    case None = 'none';
    case Single = 'single';
    case Double = 'double';
    case Dashed = 'dashed';
    case Dotted = 'dotted';
    case Thick = 'thick';
}
