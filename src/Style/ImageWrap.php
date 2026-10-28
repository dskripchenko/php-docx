<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Style;

/** How text flows around a floating picture. */
enum ImageWrap: string
{
    /** Text ignores the picture: it sits in front of or behind the text (`wp:wrapNone`). */
    case None = 'none';
    /** Text wraps around the picture's box on both sides (`wp:wrapSquare`). */
    case Square = 'square';
    /** Text stops above the picture and resumes below it (`wp:wrapTopAndBottom`). */
    case TopAndBottom = 'topAndBottom';
}
