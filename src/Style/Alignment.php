<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Style;

/**
 * The alignment of a paragraph or a cell. The value strings match the literal
 * values of OOXML `<w:jc w:val="..."/>`.
 */
enum Alignment: string
{
    case Start = 'start';
    case Center = 'center';
    case End = 'end';
    case Justify = 'both';
    case Distribute = 'distribute';
}
