<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Style;

final readonly class Border
{
    /**
     * @param  int  $sizeEighthsOfPoint  The border width in 1/8 of a point (as
     *                                   OOXML has it). 2 = 0.25pt, 4 = 0.5pt,
     *                                   8 = 1pt, 16 = 2pt.
     * @param  string  $color  RGB hex without the `#` (`000000` for black).
     */
    public function __construct(
        public BorderStyle $style = BorderStyle::Single,
        public int $sizeEighthsOfPoint = 4,
        public string $color = '000000',
    ) {}
}
