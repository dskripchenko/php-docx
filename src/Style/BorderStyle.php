<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Style;

/**
 * The line style of a border. The values match the `<w:val>` attribute of the
 * OOXML border elements.
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
