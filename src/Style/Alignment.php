<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Style;

/**
 * Выравнивание параграфа/ячейки. Имена value-string соответствуют
 * OOXML `<w:jc w:val="..."/>` literal-значениям.
 */
enum Alignment: string
{
    case Start = 'start';
    case Center = 'center';
    case End = 'end';
    case Justify = 'both';
    case Distribute = 'distribute';
}
