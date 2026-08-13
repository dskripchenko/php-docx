<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Build;

use Dskripchenko\PhpDocx\Document;
use Dskripchenko\PhpDocx\Section;
use Dskripchenko\PhpDocx\Style\PageSetup;
use Dskripchenko\PhpDocx\Style\StyleRegistry;
use Dskripchenko\PhpDocx\Writer\Word2007Writer;

/**
 * A fluent builder that assembles a DOCX document block by block.
 *
 * It starts with `DocumentBuilder::new()`, accumulates body blocks
 * (paragraph/heading/table/list/image/pageBreak/...), headers and footers, the
 * page setup, a watermark and custom styles, and finalizes through
 * `build(): Document` (or `toBytes()`/`toFile()` straight into DOCX bytes).
 *
 * Header/footer/watermark are Phase B5.
 *
 * Example:
 * ```
 * $doc = DocumentBuilder::new()
 *     ->heading(1, 'Title')
 *     ->paragraph(fn($p) => $p
 *         ->text('Hello ')
 *         ->bold('world')
 *         ->text('!')
 *     )
 *     ->pageBreak()
 *     ->heading(2, 'Next page')
 *     ->paragraph('Body text.')
 *     ->build();
 * ```
 */
final class DocumentBuilder
{
    use AddsBlockContent;

    private PageSetup $pageSetup;

    private ?string $watermarkText = null;

    private ?StyleRegistry $styles = null;

    /** @var list<\Dskripchenko\PhpDocx\Element\BlockElement> */
    private array $headerBlocks = [];

    /** @var list<\Dskripchenko\PhpDocx\Element\BlockElement> */
    private array $footerBlocks = [];

    /** @var list<\Dskripchenko\PhpDocx\Element\BlockElement> */
    private array $firstHeaderBlocks = [];

    /** @var list<\Dskripchenko\PhpDocx\Element\BlockElement> */
    private array $firstFooterBlocks = [];

    /** @var list<\Dskripchenko\PhpDocx\Element\BlockElement> */
    private array $evenHeaderBlocks = [];

    /** @var list<\Dskripchenko\PhpDocx\Element\BlockElement> */
    private array $evenFooterBlocks = [];

    private function __construct()
    {
        $this->pageSetup = new PageSetup;
    }

    public static function new(): self
    {
        return new self;
    }

    public function pageSetup(PageSetup $pageSetup): self
    {
        $this->pageSetup = $pageSetup;

        return $this;
    }

    public function watermark(string $text): self
    {
        $this->watermarkText = $text;

        return $this;
    }

    /**
     * Override default StyleRegistry (Heading1..6 + ListParagraph + Caption).
     */
    public function styles(StyleRegistry $registry): self
    {
        $this->styles = $registry;

        return $this;
    }

    /**
     * The header section (repeated on every page).
     *
     * @param  callable(SectionContentBuilder): void  $builderCallback
     */
    public function header(callable $builderCallback): self
    {
        $b = new SectionContentBuilder;
        $builderCallback($b);
        $this->headerBlocks = $b->buildBlocks();

        return $this;
    }

    /**
     * The footer section (repeated on every page).
     *
     * @param  callable(SectionContentBuilder): void  $builderCallback
     */
    public function footer(callable $builderCallback): self
    {
        $b = new SectionContentBuilder;
        $builderCallback($b);
        $this->footerBlocks = $b->buildBlocks();

        return $this;
    }

    /**
     * A header for the first page only. The writer adds `<w:titlePg/>` to
     * sectPr automatically — otherwise Word ignores the first-page header.
     *
     * @param  callable(SectionContentBuilder): void  $builderCallback
     */
    public function firstHeader(callable $builderCallback): self
    {
        $b = new SectionContentBuilder;
        $builderCallback($b);
        $this->firstHeaderBlocks = $b->buildBlocks();

        return $this;
    }

    /**
     * A footer for the first page only.
     *
     * @param  callable(SectionContentBuilder): void  $builderCallback
     */
    public function firstFooter(callable $builderCallback): self
    {
        $b = new SectionContentBuilder;
        $builderCallback($b);
        $this->firstFooterBlocks = $b->buildBlocks();

        return $this;
    }

    /**
     * A header for even pages. The writer adds `<w:evenAndOddHeaders/>` to
     * word/settings.xml automatically — otherwise Word ignores the even-page
     * header.
     *
     * @param  callable(SectionContentBuilder): void  $builderCallback
     */
    public function evenHeader(callable $builderCallback): self
    {
        $b = new SectionContentBuilder;
        $builderCallback($b);
        $this->evenHeaderBlocks = $b->buildBlocks();

        return $this;
    }

    /**
     * A footer for even pages.
     *
     * @param  callable(SectionContentBuilder): void  $builderCallback
     */
    public function evenFooter(callable $builderCallback): self
    {
        $b = new SectionContentBuilder;
        $builderCallback($b);
        $this->evenFooterBlocks = $b->buildBlocks();

        return $this;
    }

    public function build(): Document
    {
        return new Document(
            section: new Section(
                body: $this->buildBlocks(),
                header: $this->headerBlocks,
                footer: $this->footerBlocks,
                pageSetup: $this->pageSetup,
                firstHeader: $this->firstHeaderBlocks,
                firstFooter: $this->firstFooterBlocks,
                evenHeader: $this->evenHeaderBlocks,
                evenFooter: $this->evenFooterBlocks,
            ),
            watermarkText: $this->watermarkText,
        );
    }

    /**
     * Writes the DOCX bytes right away. Handy when the Document AST is not
     * needed.
     */
    public function toBytes(): string
    {
        $writer = $this->styles !== null
            ? new Word2007Writer($this->styles)
            : new Word2007Writer;

        return $writer->write($this->build());
    }

    /**
     * Writes the DOCX to a file. Returns the number of bytes written.
     */
    public function toFile(string $path): int
    {
        $bytes = $this->toBytes();
        $written = file_put_contents($path, $bytes);
        if ($written === false) {
            throw new \RuntimeException('Не удалось записать DOCX в '.$path);
        }

        return $written;
    }
}
