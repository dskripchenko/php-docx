<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Style;

/** What a floating picture's horizontal position is measured from (`wp:positionH/@relativeFrom`). */
enum HorizontalAnchor: string
{
    case Page = 'page';
    case Margin = 'margin';
    case Column = 'column';
    case Character = 'character';
}
