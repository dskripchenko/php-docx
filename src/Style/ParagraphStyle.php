<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Style;

/**
 * Стиль параграфа (`<w:pPr>` в OOXML).
 *
 * Пространство (spaceBefore/After) — в twips.
 * Отступы (indent*) — в twips.
 */
final readonly class ParagraphStyle
{
    public function __construct(
        public Alignment $alignment = Alignment::Start,
        public int $spaceBeforeTwips = 0,
        public int $spaceAfterTwips = 0,
        public int $indentLeftTwips = 0,
        public int $indentRightTwips = 0,
        public int $indentFirstLineTwips = 0,
        public ?int $lineSpacingTwips = null,
        public bool $pageBreakAfter = false,
        public ?BorderSet $borders = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->alignment === Alignment::Start
            && $this->spaceBeforeTwips === 0
            && $this->spaceAfterTwips === 0
            && $this->indentLeftTwips === 0
            && $this->indentRightTwips === 0
            && $this->indentFirstLineTwips === 0
            && $this->lineSpacingTwips === null
            && ! $this->pageBreakAfter
            && $this->borders === null;
    }
}
