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
 * Mutable fluent builder для одного Paragraph.
 *
 * Inline-content API через `AddsInlineContent` trait: text/bold/italic/
 * underline/strike/sup/sub/lineBreak/styled/link/internalLink/bookmark/
 * pageNumber/totalPages/currentDate/currentTime/mergeField/image*.
 *
 * Paragraph-level — alignment/indent/spacing/borders/headingLevel.
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
        $toTwips = static fn (?float $mm): ?int => $mm === null ? null : (int) round($mm * 56.6929);

        return $this->indent($toTwips($left), $toTwips($right), $toTwips($firstLine));
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
