<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Style;

/**
 * Стиль run'а (`<w:rPr>` в OOXML) — атрибуты непрерывного куска текста.
 *
 * Размеры шрифта — в half-points (OOXML native: `<w:sz w:val="32"/>` = 16pt).
 * Цвета — RGB hex без `#`, lowercase (`14b8a6`).
 */
final readonly class RunStyle
{
    public function __construct(
        public ?int $sizeHalfPoints = null,
        public ?string $color = null,
        public ?string $backgroundColor = null,
        public ?string $fontFamily = null,
        public bool $bold = false,
        public bool $italic = false,
        public bool $underline = false,
        public bool $strikethrough = false,
        public bool $superscript = false,
        public bool $subscript = false,
    ) {}

    public static function fromPt(
        ?float $sizePt = null,
        ?string $color = null,
        ?string $backgroundColor = null,
        ?string $fontFamily = null,
        bool $bold = false,
        bool $italic = false,
        bool $underline = false,
        bool $strikethrough = false,
        bool $superscript = false,
        bool $subscript = false,
    ): self {
        return new self(
            sizeHalfPoints: $sizePt !== null ? (int) round($sizePt * 2) : null,
            color: $color,
            backgroundColor: $backgroundColor,
            fontFamily: $fontFamily,
            bold: $bold,
            italic: $italic,
            underline: $underline,
            strikethrough: $strikethrough,
            superscript: $superscript,
            subscript: $subscript,
        );
    }

    public function withBold(bool $value = true): self
    {
        return new self(
            $this->sizeHalfPoints,
            $this->color,
            $this->backgroundColor,
            $this->fontFamily,
            $value,
            $this->italic,
            $this->underline,
            $this->strikethrough,
            $this->superscript,
            $this->subscript,
        );
    }

    public function isEmpty(): bool
    {
        return $this->sizeHalfPoints === null
            && $this->color === null
            && $this->backgroundColor === null
            && $this->fontFamily === null
            && ! $this->bold && ! $this->italic && ! $this->underline
            && ! $this->strikethrough && ! $this->superscript && ! $this->subscript;
    }
}
