<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Build;

use Dskripchenko\PhpDocx\Style\RunStyle;

/**
 * A fluent builder for RunStyle.
 *
 * Used in `->styled('text', fn(RunStyleBuilder $s) => $s->color('ff0000'))` to
 * configure the style of a single run.
 *
 * It also works standalone: `RunStyleBuilder::new()->bold()->build()` gives a
 * ready RunStyle to pass into ->text($text, RunStyle).
 *
 * Sizes are in pt; colours are hex (with or without the `#`).
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
     * Starts from an existing RunStyle (to modify it incrementally).
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
     * A colour in hex (with or without the `#`, lower or upper case).
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
     * The size in points (pt). Converted into the half-points of OOXML.
     */
    public function fontSizePt(float $pt): self
    {
        $this->sizeHalfPoints = (int) round($pt * 2);

        return $this;
    }

    /**
     * A named highlight (yellow|green|cyan|magenta|blue|red|darkBlue|
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
