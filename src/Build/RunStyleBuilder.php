<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Build;

use Dskripchenko\PhpDocx\Style\RunStyle;

/**
 * Fluent builder для RunStyle.
 *
 * Используется в `->styled('text', fn(RunStyleBuilder $s) => $s->color('ff0000'))`
 * для одноразовой настройки стиля runa.
 *
 * Также можно использовать standalone: `RunStyleBuilder::new()->bold()->build()`
 * → готовый RunStyle для передачи в ->text($text, RunStyle).
 *
 * Размеры — pt; цвета — hex (с `#` или без).
 */
final class RunStyleBuilder
{
    private ?int $sizeHalfPoints = null;

    private ?string $color = null;

    private ?string $backgroundColor = null;

    private ?string $fontFamily = null;

    private bool $bold = false;

    private bool $italic = false;

    private bool $underline = false;

    private bool $strikethrough = false;

    private bool $superscript = false;

    private bool $subscript = false;

    private ?string $highlight = null;

    public static function new(): self
    {
        return new self;
    }

    /**
     * Стартовать с уже существующего RunStyle (для добавочной модификации).
     */
    public static function from(RunStyle $base): self
    {
        $b = new self;
        $b->sizeHalfPoints = $base->sizeHalfPoints;
        $b->color = $base->color;
        $b->backgroundColor = $base->backgroundColor;
        $b->fontFamily = $base->fontFamily;
        $b->bold = $base->bold;
        $b->italic = $base->italic;
        $b->underline = $base->underline;
        $b->strikethrough = $base->strikethrough;
        $b->superscript = $base->superscript;
        $b->subscript = $base->subscript;
        $b->highlight = $base->highlight;

        return $b;
    }

    public function bold(bool $value = true): self
    {
        $this->bold = $value;

        return $this;
    }

    public function italic(bool $value = true): self
    {
        $this->italic = $value;

        return $this;
    }

    public function underline(bool $value = true): self
    {
        $this->underline = $value;

        return $this;
    }

    public function strike(bool $value = true): self
    {
        $this->strikethrough = $value;

        return $this;
    }

    public function superscript(bool $value = true): self
    {
        $this->superscript = $value;
        $this->subscript = false;

        return $this;
    }

    public function subscript(bool $value = true): self
    {
        $this->subscript = $value;
        $this->superscript = false;

        return $this;
    }

    /**
     * Цвет в hex (с `#` или без, lowercase или upper).
     */
    public function color(string $hex): self
    {
        $this->color = strtolower(ltrim($hex, '#'));

        return $this;
    }

    public function backgroundColor(string $hex): self
    {
        $this->backgroundColor = strtolower(ltrim($hex, '#'));

        return $this;
    }

    public function fontFamily(string $name): self
    {
        $this->fontFamily = $name;

        return $this;
    }

    /**
     * Размер в пунктах (pt). Конвертируется в OOXML half-points.
     */
    public function fontSizePt(float $pt): self
    {
        $this->sizeHalfPoints = (int) round($pt * 2);

        return $this;
    }

    /**
     * Названный highlight (yellow|green|cyan|magenta|blue|red|darkBlue|
     * darkCyan|darkGreen|darkMagenta|darkRed|darkYellow|darkGray|lightGray|
     * black|none).
     */
    public function highlight(string $namedColor): self
    {
        $this->highlight = $namedColor;

        return $this;
    }

    public function build(): RunStyle
    {
        return new RunStyle(
            sizeHalfPoints: $this->sizeHalfPoints,
            color: $this->color,
            backgroundColor: $this->backgroundColor,
            fontFamily: $this->fontFamily,
            bold: $this->bold,
            italic: $this->italic,
            underline: $this->underline,
            strikethrough: $this->strikethrough,
            superscript: $this->superscript,
            subscript: $this->subscript,
            highlight: $this->highlight,
        );
    }
}
