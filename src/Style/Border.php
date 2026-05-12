<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Style;

final readonly class Border
{
    /**
     * @param  int  $sizeEighthsOfPoint  Толщина бордера в 1/8 пункта (OOXML native).
     *                                   2 = 0.25pt, 4 = 0.5pt, 8 = 1pt, 16 = 2pt.
     * @param  string  $color  RGB hex без `#` (`000000` для чёрного).
     */
    public function __construct(
        public BorderStyle $style = BorderStyle::Single,
        public int $sizeEighthsOfPoint = 4,
        public string $color = '000000',
    ) {}
}
