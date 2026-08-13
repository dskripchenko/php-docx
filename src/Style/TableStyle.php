<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Style;

final readonly class TableStyle
{
    /**
     * @param  int|null  $widthTwips  Width in twips (`w:type="dxa"`).
     * @param  int|null  $widthPercent  Width as % × 50 (`w:type="pct"`). When
     *                                  both are null, Word renders auto width.
     * @param  bool  $fixedLayout  true → `<w:tblLayout w:type="fixed"/>`: the
     *                             cell widths are applied literally, without
     *                             Word's auto-fitting.
     */
    public function __construct(
        public ?int $widthTwips = null,
        public ?int $widthPercent = null,
        public bool $fixedLayout = false,
        public ?BorderSet $borders = null,
        public Alignment $alignment = Alignment::Start,
        public int $cellMarginTopTwips = 0,
        public int $cellMarginRightTwips = 80,
        public int $cellMarginBottomTwips = 0,
        public int $cellMarginLeftTwips = 80,
    ) {}
}
