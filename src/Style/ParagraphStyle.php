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
        /**
         * Как понимать `lineSpacingTwips`: `auto` — доля от одинарного
         * интервала (240 = один), `exact`/`atLeast` — высота строки.
         */
        public ?string $lineSpacingRule = null,
        public bool $pageBreakAfter = false,
        public ?BorderSet $borders = null,
        /** Заливка абзаца (`<w:shd>` в `w:pPr`) — hex без `#`, например `0f766e`. */
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
            && $this->borders === null
            && $this->shadingColor === null;
    }

    /**
     * Immutable-update: возвращает копию с overridden-полями.
     * Параметры с дефолтом `null` остаются как у `$this`.
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
            borders: $borders ?? $this->borders,
            shadingColor: $shadingColor ?? $this->shadingColor,
        );
    }
}
