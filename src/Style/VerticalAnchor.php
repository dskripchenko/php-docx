<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Style;

/** What a floating picture's vertical position is measured from (`wp:positionV/@relativeFrom`). */
enum VerticalAnchor: string
{
    case Page = 'page';
    case Margin = 'margin';
    case Paragraph = 'paragraph';
    case Line = 'line';
}
