<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Build;

use Dskripchenko\PhpDocx\Element\InlineElement;
use Dskripchenko\PhpDocx\Element\Paragraph;
use Dskripchenko\PhpDocx\Style\Alignment;
use Dskripchenko\PhpDocx\Style\BorderSet;
use Dskripchenko\PhpDocx\Style\ParagraphStyle;
use Dskripchenko\PhpDocx\Style\RunStyle;

/**
 * A mutable fluent builder for a single Paragraph.
 *
 * The inline-content API comes from the `AddsInlineContent` trait: text/bold/
 * italic/underline/strike/sup/sub/lineBreak/styled/link/internalLink/bookmark/
 * pageNumber/totalPages/currentDate/currentTime/mergeField/image*.
 *
 * At paragraph level: alignment/indent/spacing/borders/headingLevel.
 */
final class ParagraphBuilder
{
    use AddsInlineContent;

    private ParagraphStyle $style;

    private ?int $headingLevel = null;

    public function __construct(
        ?ParagraphStyle $style = null,
        ?RunStyle $defaultRunStyle = null,
        ?int $headingLevel = null,
    ) {
        $this->style = $style ?? new ParagraphStyle;
        $this->defaultRunStyle = $defaultRunStyle ?? new RunStyle;
        $this->headingLevel = $headingLevel;
    }

    public function style(ParagraphStyle $style): self
    {
        $this->style = $style;

        return $this;
    }

    public function headingLevel(?int $level): self
    {
        $this->headingLevel = $level;

        return $this;
    }

    // ─────────── Paragraph-style shortcuts ────────────────────────────────

    public function align(Alignment $alignment): self
    {
        $this->style = $this->style->copy(alignment: $alignment);

        return $this;
    }

    public function alignCenter(): self
    {
        return $this->align(Alignment::Center);
    }

    public function alignRight(): self
    {
        return $this->align(Alignment::End);
    }

    public function alignJustify(): self
    {
        return $this->align(Alignment::Justify);
    }

    public function indent(?int $left = null, ?int $right = null, ?int $firstLine = null): self
    {
        $this->style = $this->style->copy(
            indentLeftTwips: $left,
            indentRightTwips: $right,
            indentFirstLineTwips: $firstLine,
        );

        return $this;
    }

    public function indentMm(?float $left = null, ?float $right = null, ?float $firstLine = null): self
    {
        return $this->indent(
            $left === null ? null : Length::mm($left),
            $right === null ? null : Length::mm($right),
            $firstLine === null ? null : Length::mm($firstLine),
        );
    }

    public function indentCm(?float $left = null, ?float $right = null, ?float $firstLine = null): self
    {
        return $this->indent(
            $left === null ? null : Length::cm($left),
            $right === null ? null : Length::cm($right),
            $firstLine === null ? null : Length::cm($firstLine),
        );
    }

    public function indentPt(?float $left = null, ?float $right = null, ?float $firstLine = null): self
    {
        return $this->indent(
            $left === null ? null : Length::pt($left),
            $right === null ? null : Length::pt($right),
            $firstLine === null ? null : Length::pt($firstLine),
        );
    }

    public function indentInches(?float $left = null, ?float $right = null, ?float $firstLine = null): self
    {
        return $this->indent(
            $left === null ? null : Length::inch($left),
            $right === null ? null : Length::inch($right),
            $firstLine === null ? null : Length::inch($firstLine),
        );
    }

    public function spacing(?int $before = null, ?int $after = null, ?int $line = null): self
    {
        $this->style = $this->style->copy(
            spaceBeforeTwips: $before,
            spaceAfterTwips: $after,
            lineSpacingTwips: $line,
        );

        return $this;
    }

    public function spacingPt(?float $before = null, ?float $after = null, ?float $line = null): self
    {
        return $this->spacing(
            $before === null ? null : Length::pt($before),
            $after === null ? null : Length::pt($after),
            $line === null ? null : Length::pt($line),
        );
    }

    public function spacingMm(?float $before = null, ?float $after = null, ?float $line = null): self
    {
        return $this->spacing(
            $before === null ? null : Length::mm($before),
            $after === null ? null : Length::mm($after),
            $line === null ? null : Length::mm($line),
        );
    }

    public function borders(BorderSet $borders): self
    {
        $this->style = $this->style->copy(borders: $borders);

        return $this;
    }

    /**
     * @return list<InlineElement>
     */
    public function buildInlines(): array
    {
        return $this->children;
    }

    public function build(): Paragraph
    {
        return new Paragraph(
            children: $this->children,
            style: $this->style,
            headingLevel: $this->headingLevel,
        );
    }
}
