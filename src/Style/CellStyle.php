<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Style;

final readonly class CellStyle
{
    /**
     * @param  int|null  $widthTwips  Ширина в twips (для `w:type="dxa"`).
     * @param  int|null  $widthPercent  Ширина в процентах × 50 (`w:type="pct"`).
     *                                  Используется если widthTwips=null.
     * @param  int  $paddingTopTwips  Inner padding (в `<w:tcMar>`).
     */
    public function __construct(
        public ?int $widthTwips = null,
        public ?int $widthPercent = null,
        public int $paddingTopTwips = 0,
        public int $paddingRightTwips = 0,
        public int $paddingBottomTwips = 0,
        public int $paddingLeftTwips = 0,
        public VerticalAlign $verticalAlign = VerticalAlign::Top,
        public ?string $backgroundColor = null,
        public ?BorderSet $borders = null,
        public int $gridSpan = 1,
        public int $rowSpan = 1,
    ) {}
}
