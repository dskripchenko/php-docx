<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Style;

final readonly class TableStyle
{
    /**
     * @param  int|null  $widthTwips  Ширина в twips (`w:type="dxa"`).
     * @param  int|null  $widthPercent  Ширина в % × 50 (`w:type="pct"`).
     *                                  Если оба null — Word рендерит auto-width.
     * @param  bool  $fixedLayout  true → `<w:tblLayout w:type="fixed"/>` —
     *                             cell widths применяются буквально без
     *                             Word'овской авто-фит'илизации.
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
