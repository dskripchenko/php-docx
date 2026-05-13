<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Build;

use Dskripchenko\PhpDocx\Element\InlineElement;
use Dskripchenko\PhpDocx\Element\LineBreak;
use Dskripchenko\PhpDocx\Element\Paragraph;
use Dskripchenko\PhpDocx\Element\Run;
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

    public function build(): Paragraph
    {
        return new Paragraph(
            children: $this->children,
            style: $this->style,
            headingLevel: $this->headingLevel,
        );
    }
}
