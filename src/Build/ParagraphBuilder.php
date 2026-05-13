<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Build;

use Dskripchenko\PhpDocx\Element\InlineElement;
use Dskripchenko\PhpDocx\Element\LineBreak;
use Dskripchenko\PhpDocx\Element\Paragraph;
use Dskripchenko\PhpDocx\Element\Run;
use Dskripchenko\PhpDocx\Style\Alignment;
use Dskripchenko\PhpDocx\Style\BorderSet;
use Dskripchenko\PhpDocx\Style\ParagraphStyle;
use Dskripchenko\PhpDocx\Style\RunStyle;

/**
 * Mutable fluent builder для одного Paragraph. Finalize'ит через build().
 *
 * Базовые text-acceptor'ы: `->text/bold/italic/underline/strike/sup/sub/lineBreak`.
 * Расширенный стиль — в Phase B4 (RunStyleBuilder).
 *
 * Используется как `$doc->paragraph(fn($p) => $p->text(...)->bold(...))`.
 */
final class ParagraphBuilder
{
    /** @var list<InlineElement> */
    private array $children = [];

    private ParagraphStyle $style;

    private ?int $headingLevel = null;

    /** Базовый RunStyle, который наследуют все добавленные runs (override через `with*-helper`). */
    private RunStyle $defaultRunStyle;

    public function __construct(
        ?ParagraphStyle $style = null,
        ?RunStyle $defaultRunStyle = null,
        ?int $headingLevel = null,
    ) {
        $this->style = $style ?? new ParagraphStyle;
        $this->defaultRunStyle = $defaultRunStyle ?? new RunStyle;
        $this->headingLevel = $headingLevel;
    }

    /**
     * Произвольный inline-элемент (Run / LineBreak / Hyperlink / Image / Field / Bookmark).
     */
    public function add(InlineElement $element): self
    {
        $this->children[] = $element;

        return $this;
    }

    /**
     * Plain text run. Если передан $style — переопределяет defaultRunStyle.
     */
    public function text(string $text, ?RunStyle $style = null): self
    {
        $this->children[] = new Run($text, $style ?? $this->defaultRunStyle);

        return $this;
    }

    public function bold(string $text): self
    {
        return $this->text($text, $this->defaultRunStyle->withBold());
    }

    public function italic(string $text): self
    {
        return $this->text($text, $this->defaultRunStyle->withItalic());
    }

    public function underline(string $text): self
    {
        return $this->text($text, $this->defaultRunStyle->withUnderline());
    }

    public function strike(string $text): self
    {
        return $this->text($text, $this->defaultRunStyle->withStrikethrough());
    }

    public function sup(string $text): self
    {
        return $this->text($text, $this->defaultRunStyle->withSuperscript());
    }

    public function sub(string $text): self
    {
        return $this->text($text, $this->defaultRunStyle->withSubscript());
    }

    public function lineBreak(): self
    {
        $this->children[] = new LineBreak;

        return $this;
    }

    /**
     * Run с custom-стилем через RunStyleBuilder closure.
     *
     *   ->styled('Important', fn($s) => $s->color('ff0000')->bold())
     */
    public function styled(string $text, callable $styleCallback): self
    {
        $builder = RunStyleBuilder::from($this->defaultRunStyle);
        $styleCallback($builder);

        return $this->text($text, $builder->build());
    }

    /**
     * Меняет default run-style для последующих text/bold/etc вызовов.
     * Полезно когда нужно задать base font/size для всего параграфа.
     */
    public function withRunStyle(RunStyle $style): self
    {
        $this->defaultRunStyle = $style;

        return $this;
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

    /**
     * Отступы в twips (1 twip = 1/20 pt). Любой параметр null оставляет
     * текущее значение.
     */
    public function indent(?int $left = null, ?int $right = null, ?int $firstLine = null): self
    {
        $this->style = $this->style->copy(
            indentLeftTwips: $left,
            indentRightTwips: $right,
            indentFirstLineTwips: $firstLine,
        );

        return $this;
    }

    /**
     * Convenience: отступы в миллиметрах (конвертируется в twips).
     */
    public function indentMm(?float $left = null, ?float $right = null, ?float $firstLine = null): self
    {
        $toTwips = static fn (?float $mm): ?int => $mm === null ? null : (int) round($mm * 56.6929);

        return $this->indent(
            $toTwips($left),
            $toTwips($right),
            $toTwips($firstLine),
        );
    }

    /**
     * Spacing вокруг параграфа в twips.
     */
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

    public function build(): Paragraph
    {
        return new Paragraph(
            children: $this->children,
            style: $this->style,
            headingLevel: $this->headingLevel,
        );
    }
}
