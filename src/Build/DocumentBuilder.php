<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Build;

use Dskripchenko\PhpDocx\Document;
use Dskripchenko\PhpDocx\Section;
use Dskripchenko\PhpDocx\Style\PageSetup;
use Dskripchenko\PhpDocx\Style\StyleRegistry;
use Dskripchenko\PhpDocx\Writer\Word2007Writer;

/**
 * Fluent builder для построения DOCX-документа поблочно.
 *
 * Стартует через `DocumentBuilder::new()`, накапливает блоки тела
 * (paragraph/heading/table/list/image/pageBreak/...), header'ы/footer'ы,
 * page setup, watermark, custom styles, finalize'ит через `build(): Document`
 * (или `toBytes()`/`toFile()` напрямую в DOCX-bytes).
 *
 * Header/footer/watermark — Phase B5.
 *
 * Пример:
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
     * Header section (повторяется на каждой странице).
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
     * Footer section (повторяется на каждой странице).
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
     * Header только для первой страницы. Writer автоматически добавит
     * `<w:titlePg/>` в sectPr — иначе Word проигнорирует first-header.
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
     * Footer только для первой страницы.
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
     * Header для чётных страниц. Writer автоматически добавит
     * `<w:evenAndOddHeaders/>` в word/settings.xml — иначе Word
     * проигнорирует even-header.
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
     * Footer для чётных страниц.
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
     * Сразу пишет DOCX bytes. Удобно когда не нужно держать Document AST.
     */
    public function toBytes(): string
    {
        $writer = $this->styles !== null
            ? new Word2007Writer($this->styles)
            : new Word2007Writer;

        return $writer->write($this->build());
    }

    /**
     * Пишет DOCX в файл. Возвращает количество записанных байт.
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
