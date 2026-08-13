<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Style;

final readonly class CellStyle
{
    /**
     * @param  int|null  $widthTwips  Width in twips (for `w:type="dxa"`).
     * @param  int|null  $widthPercent  Width as a percentage × 50
     *                                  (`w:type="pct"`). Used when
     *                                  widthTwips is null.
     * @param  int  $paddingTopTwips  Inner padding (in `<w:tcMar>`).
     * @param  int  $rowSpan  The logical number of rows the cell spans (on the
     *                        starting cell). >1 makes the renderer emit
     *                        `<w:vMerge w:val="restart"/>`. The non-primary
     *                        rows get auto-inserted cells with
     *                        `$vMergeContinue=true`.
     * @param  bool  $vMergeContinue  The "rowSpan merge continues" marker — the
     *                                renderer emits `<w:vMerge/>` without val.
     *                                Such cells are created automatically by
     *                                the converter; a caller rarely sets it.
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
        public bool $vMergeContinue = false,
    ) {}
}
