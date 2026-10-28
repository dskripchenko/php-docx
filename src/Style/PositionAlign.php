<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Style;

/**
 * A floating picture aligned within what it is measured from (`wp:align`)
 * instead of placed at an offset. Left and Right are horizontal only, Top and
 * Bottom vertical only; the others work on both axes.
 */
enum PositionAlign: string
{
    case Left = 'left';
    case Right = 'right';
    case Top = 'top';
    case Bottom = 'bottom';
    case Center = 'center';
    case Inside = 'inside';
    case Outside = 'outside';
}
