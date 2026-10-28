<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Style;

/**
 * Where a section begins (`<w:type>` in its `<w:sectPr>`): on a new page (the
 * default, written as no `w:type` at all), straight after the previous
 * section on the same page, or on the next even or odd page. The first
 * section's start has no effect.
 */
enum SectionStart: string
{
    case NextPage = 'nextPage';
    case Continuous = 'continuous';
    case EvenPage = 'evenPage';
    case OddPage = 'oddPage';
}
