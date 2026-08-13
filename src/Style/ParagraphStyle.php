<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Style;

/**
 * A paragraph style (`<w:pPr>` in OOXML).
 *
 * Spacing (spaceBefore/After) is in twips.
 * Indents (indent*) are in twips.
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
        /**
         * How to read `lineSpacingTwips`: under `auto` it is a fraction of
         * single spacing (240 = single), under `exact`/`atLeast` it is the
         * line height.
         */
        public ?string $lineSpacingRule = null,
        public bool $pageBreakAfter = false,
        /**
         * `w:keepNext` — "keep with the next paragraph".
         *
         * A section heading must not be left as the last line of a page: when
         * nothing fits after it, Word carries both over to the next page.
         */
        public bool $keepWithNext = false,
        public ?BorderSet $borders = null,
        /** The paragraph fill (`<w:shd>` in `w:pPr`) — hex without the `#`, e.g. `0f766e`. */
        public ?string $shadingColor = null,
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
            && ! $this->keepWithNext
            && $this->borders === null
            && $this->shadingColor === null;
    }

    /**
     * An immutable update: returns a copy with the overridden fields. The
     * parameters left at their `null` default keep the values of `$this`.
     */
    public function copy(
        ?Alignment $alignment = null,
        ?int $spaceBeforeTwips = null,
        ?int $spaceAfterTwips = null,
        ?int $indentLeftTwips = null,
        ?int $indentRightTwips = null,
        ?int $indentFirstLineTwips = null,
        ?int $lineSpacingTwips = null,
        ?string $lineSpacingRule = null,
        ?bool $pageBreakAfter = null,
        ?bool $keepWithNext = null,
        ?BorderSet $borders = null,
        ?string $shadingColor = null,
    ): self {
        return new self(
            alignment: $alignment ?? $this->alignment,
            spaceBeforeTwips: $spaceBeforeTwips ?? $this->spaceBeforeTwips,
            spaceAfterTwips: $spaceAfterTwips ?? $this->spaceAfterTwips,
            indentLeftTwips: $indentLeftTwips ?? $this->indentLeftTwips,
            indentRightTwips: $indentRightTwips ?? $this->indentRightTwips,
            indentFirstLineTwips: $indentFirstLineTwips ?? $this->indentFirstLineTwips,
            lineSpacingTwips: $lineSpacingTwips ?? $this->lineSpacingTwips,
            lineSpacingRule: $lineSpacingRule ?? $this->lineSpacingRule,
            pageBreakAfter: $pageBreakAfter ?? $this->pageBreakAfter,
            keepWithNext: $keepWithNext ?? $this->keepWithNext,
            borders: $borders ?? $this->borders,
            shadingColor: $shadingColor ?? $this->shadingColor,
        );
    }
}
