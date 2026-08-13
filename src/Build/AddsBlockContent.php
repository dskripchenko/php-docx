<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Build;

use Dskripchenko\PhpDocx\Element\BlockElement;
use Dskripchenko\PhpDocx\Element\HorizontalRule;
use Dskripchenko\PhpDocx\Element\Image;
use Dskripchenko\PhpDocx\Element\PageBreak;
use Dskripchenko\PhpDocx\Element\Paragraph;
use Dskripchenko\PhpDocx\Element\Run;
use Dskripchenko\PhpDocx\Style\ParagraphStyle;
use Dskripchenko\PhpDocx\Style\RunStyle;

/**
 * A trait shared by every builder that accumulates block content:
 *  - DocumentBuilder (body)
 *  - HeaderFooterBuilder
 *  - TableCellBuilder
 *
 * It implements all the block adders uniformly, keeping state in `$this->blocks`.
 */
trait AddsBlockContent
{
    /** @var list<BlockElement> */
    private array $blocks = [];

    /**
     * A paragraph. The short form is just a text string:
     *   `->paragraph('Hello')`
     * The extended form is a callback taking a ParagraphBuilder:
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
     * A heading of level 1..6. Short and long forms as in paragraph().
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
     * A table, as a closure taking a TableBuilder.
     *
     * @param  callable(TableBuilder): void  $builderCallback
     */
    public function table(callable $builderCallback): self
    {
        $t = new TableBuilder;
        $builderCallback($t);
        $this->blocks[] = $t->build();

        return $this;
    }

    /**
     * A bullet list, the equivalent of `<ul>`.
     *
     * @param  callable(ListBuilder): void  $builderCallback
     */
    public function bulletList(callable $builderCallback): self
    {
        $l = new ListBuilder(ordered: false);
        $builderCallback($l);
        $this->blocks[] = $l->build();

        return $this;
    }

    /**
     * An ordered list, the equivalent of `<ol>`. Format and startAt are set
     * inside the builder: `$l->format(ListFormat::LowerLetter)->startAt(3)`.
     *
     * @param  callable(ListBuilder): void  $builderCallback
     */
    public function orderedList(callable $builderCallback): self
    {
        $l = new ListBuilder(ordered: true);
        $builderCallback($l);
        $this->blocks[] = $l->build();

        return $this;
    }

    /**
     * A block image (as its own paragraph). A wrapper around the inline image
     * (Image is an InlineElement, but a BlockElement as well).
     */
    public function image(Image $image): self
    {
        $this->blocks[] = $image;

        return $this;
    }

    /**
     * A wrapper around Image::fromPx for a byte-based image block.
     */
    public function imageFromBytes(
        string $binary,
        \Dskripchenko\PhpDocx\Element\ImageFormat $format,
        int $widthPx,
        int $heightPx,
        ?string $altText = null,
    ): self {
        $this->blocks[] = \Dskripchenko\PhpDocx\Element\Image::fromPx(
            $binary, $format, $widthPx, $heightPx, $altText
        );

        return $this;
    }

    /**
     * An image from a file (format and dimensions are auto-detected).
     */
    public function imageFromFile(
        string $path,
        ?int $widthPx = null,
        ?int $heightPx = null,
        ?string $altText = null,
    ): self {
        // Delegate through a temporary ParagraphBuilder, which has the same
        // imageFromFile in AddsInlineContent. Then take the Image out of it and
        // put it in as a block.
        $temp = new ParagraphBuilder;
        $temp->imageFromFile($path, $widthPx, $heightPx, $altText);
        $inlines = $temp->buildInlines();
        foreach ($inlines as $el) {
            if ($el instanceof Image) {
                $this->blocks[] = $el;
            }
        }

        return $this;
    }

    /**
     * Appends a pre-built BlockElement (or several). Convenient for integrating
     * with AST code — inserting a Paragraph assembled elsewhere, for example.
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
     * An empty line (an empty paragraph) — a handy shortcut for vertical gaps
     * in the layout.
     */
    public function emptyLine(): self
    {
        $this->blocks[] = new Paragraph([]);

        return $this;
    }

    /**
     * The accumulated blocks.
     *
     * @return list<BlockElement>
     */
    public function buildBlocks(): array
    {
        return $this->blocks;
    }

    /**
     * Clears the block buffer (to reuse the builder in loops).
     */
    public function clearBlocks(): self
    {
        $this->blocks = [];

        return $this;
    }
}
