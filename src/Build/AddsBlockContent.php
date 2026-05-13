<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Build;

use Dskripchenko\PhpDocx\Element\BlockElement;
use Dskripchenko\PhpDocx\Element\HorizontalRule;
use Dskripchenko\PhpDocx\Element\PageBreak;
use Dskripchenko\PhpDocx\Element\Paragraph;
use Dskripchenko\PhpDocx\Element\Run;
use Dskripchenko\PhpDocx\Style\ParagraphStyle;
use Dskripchenko\PhpDocx\Style\RunStyle;

/**
 * Trait shared между всеми builder'ами, которые накапливают block-content:
 *  - DocumentBuilder (body)
 *  - HeaderFooterBuilder
 *  - TableCellBuilder
 *
 * Реализует все блок-adder'ы единообразно — сохраняет state в `$this->blocks`.
 */
trait AddsBlockContent
{
    /** @var list<BlockElement> */
    private array $blocks = [];

    /**
     * Параграф. Короткая форма — просто строка text:
     *   `->paragraph('Hello')`
     * Расширенная форма — callback с ParagraphBuilder:
     *   `->paragraph(fn($p) => $p->text('Hello ')->bold('world'))`
     */
    public function paragraph(string|callable $textOrBuilder): self
    {
        $p = new ParagraphBuilder;
        if (is_string($textOrBuilder)) {
            $p->text($textOrBuilder);
        } else {
            $textOrBuilder($p);
        }
        $this->blocks[] = $p->build();

        return $this;
    }

    /**
     * Заголовок уровня 1..6. Короткая и длинная формы как у paragraph().
     */
    public function heading(int $level, string|callable $textOrBuilder): self
    {
        if ($level < 1 || $level > 6) {
            throw new \InvalidArgumentException('Heading level must be 1..6, got '.$level);
        }
        $p = new ParagraphBuilder(headingLevel: $level);
        if (is_string($textOrBuilder)) {
            $p->text($textOrBuilder);
        } else {
            $textOrBuilder($p);
            $p->headingLevel($level); // на случай если callback сбросил
        }
        $this->blocks[] = $p->build();

        return $this;
    }

    public function pageBreak(): self
    {
        $this->blocks[] = new PageBreak;

        return $this;
    }

    public function horizontalRule(): self
    {
        $this->blocks[] = new HorizontalRule;

        return $this;
    }

    /**
     * Append pre-built BlockElement (или несколько). Удобно для интеграции
     * с AST-кодом — например, вставить Paragraph, собранный извне.
     *
     * @param  iterable<BlockElement>|BlockElement  $block
     */
    public function block(iterable|BlockElement $block): self
    {
        if ($block instanceof BlockElement) {
            $this->blocks[] = $block;

            return $this;
        }
        foreach ($block as $b) {
            if ($b instanceof BlockElement) {
                $this->blocks[] = $b;
            }
        }

        return $this;
    }

    /**
     * Пустая строка (empty paragraph) — удобный shortcut для вертикальных
     * gaps в layout'е.
     */
    public function emptyLine(): self
    {
        $this->blocks[] = new Paragraph([]);

        return $this;
    }

    /**
     * Накопленные блоки.
     *
     * @return list<BlockElement>
     */
    public function buildBlocks(): array
    {
        return $this->blocks;
    }

    /**
     * Очистить буфер блоков (для reuse builder'а в loop'ах).
     */
    public function clearBlocks(): self
    {
        $this->blocks = [];

        return $this;
    }
}
