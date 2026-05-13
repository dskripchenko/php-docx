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

    public function build(): Document
    {
        return new Document(
            section: new Section(
                body: $this->buildBlocks(),
                header: $this->headerBlocks,
                footer: $this->footerBlocks,
                pageSetup: $this->pageSetup,
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
