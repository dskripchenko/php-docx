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
        /** Named highlight color: `yellow`, `green`, `cyan`, `magenta`, ... — для `<w:highlight>` (16 предопределённых). */
        public ?string $highlight = null,
        /** Разрядка между символами в twips (`<w:spacing>` в `w:rPr`); отрицательная сжимает. */
        public ?int $letterSpacingTwips = null,
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
        return $this->copy(bold: $value);
    }

    public function withItalic(bool $value = true): self
    {
        return $this->copy(italic: $value);
    }

    public function withUnderline(bool $value = true): self
    {
        return $this->copy(underline: $value);
    }

    public function withStrikethrough(bool $value = true): self
    {
        return $this->copy(strikethrough: $value);
    }

    public function withSuperscript(bool $value = true): self
    {
        return $this->copy(superscript: $value, subscript: false);
    }

    public function withSubscript(bool $value = true): self
    {
        return $this->copy(subscript: $value, superscript: false);
    }

    public function withFontFamily(string $family): self
    {
        return $this->copy(fontFamily: $family);
    }

    public function withHighlight(string $highlight): self
    {
        return $this->copy(highlight: $highlight);
    }

    public function withSizeHalfPoints(int $halfPts): self
    {
        return $this->copy(sizeHalfPoints: $halfPts);
    }

    public function withLetterSpacingTwips(int $twips): self
    {
        return $this->copy(letterSpacingTwips: $twips);
    }

    private function copy(
        ?int $sizeHalfPoints = null,
        ?string $color = null,
        ?string $backgroundColor = null,
        ?string $fontFamily = null,
        ?bool $bold = null,
        ?bool $italic = null,
        ?bool $underline = null,
        ?bool $strikethrough = null,
        ?bool $superscript = null,
        ?bool $subscript = null,
        ?string $highlight = null,
        ?int $letterSpacingTwips = null,
    ): self {
        return new self(
            sizeHalfPoints: $sizeHalfPoints ?? $this->sizeHalfPoints,
            color: $color ?? $this->color,
            backgroundColor: $backgroundColor ?? $this->backgroundColor,
            fontFamily: $fontFamily ?? $this->fontFamily,
            bold: $bold ?? $this->bold,
            italic: $italic ?? $this->italic,
            underline: $underline ?? $this->underline,
            strikethrough: $strikethrough ?? $this->strikethrough,
            superscript: $superscript ?? $this->superscript,
            subscript: $subscript ?? $this->subscript,
            highlight: $highlight ?? $this->highlight,
            letterSpacingTwips: $letterSpacingTwips ?? $this->letterSpacingTwips,
        );
    }

    public function isEmpty(): bool
    {
        return $this->sizeHalfPoints === null
            && $this->color === null
            && $this->backgroundColor === null
            && $this->fontFamily === null
            && $this->highlight === null
            && $this->letterSpacingTwips === null
            && ! $this->bold && ! $this->italic && ! $this->underline
            && ! $this->strikethrough && ! $this->superscript && ! $this->subscript;
    }
}
