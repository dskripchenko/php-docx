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
     * @param  int  $rowSpan  Логическое количество строк, на которые ячейка
     *                        тянется (нач. ячейка). >1 → renderer эмитит
     *                        `<w:vMerge w:val="restart"/>`. Не-первичные
     *                        строки получают auto-inserted cells с
     *                        `$vMergeContinue=true`.
     * @param  bool  $vMergeContinue  Маркер «продолжение rowSpan-merge» —
     *                                renderer эмитит `<w:vMerge/>` без val.
     *                                Эти ячейки создаются автоматически
     *                                Converter'ом, caller обычно не выставляет.
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
