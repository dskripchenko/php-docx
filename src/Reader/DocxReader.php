<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Reader;

use Dskripchenko\PhpDocx\Document;
use Dskripchenko\PhpDocx\Element\BlockElement;
use Dskripchenko\PhpDocx\Exception\DocxException;
use Dskripchenko\PhpDocx\Section;

/**
 * Phase 8 — высокоуровневый facade.
 *
 * DOCX bytes → Document (AST). Координирует все reader'ы Phase 1-8.
 *
 * Возвращает Document с body/header/footer/watermark/pageSetup —
 * совместимо со всем существующим writer'ом (можно сразу пере-эмитить
 * через Word2007Writer).
 */
final class DocxReader
{
    /**
     * @throws DocxException
     */
    public function read(string $bytes): Document
    {
        $pkg = (new DocxPackageReader)->read($bytes);

        return $this->readPackage($pkg);
    }

    public function readPackage(DocxPackage $pkg): Document
    {
        $theme = new ThemeResolver($pkg->themeXml);
        $styles = new StylesResolver($pkg->stylesXml, $theme);
        $numbering = (new NumberingReader)->read($pkg->numberingXml);

        // Body
        $bodyEl = $pkg->documentXml->getElementsByTagNameNS(OoxmlNs::W, 'body')->item(0);
        if (! $bodyEl instanceof \DOMElement) {
            throw new DocxException('В word/document.xml отсутствует <w:body>.');
        }
        $bodyImgReader = new ImageReader($pkg, $pkg->documentPartPath);
        $bodyReader = new BodyReader(
            $styles,
            $numbering,
            $bodyImgReader,
            $pkg,
            $pkg->documentPartPath,
        );
        $body = $bodyReader->read($bodyEl);

        // Page setup
        $pageSetup = (new SectionReader)->readPageSetup($bodyEl);

        // Headers (берём первый — Phase 8 не различает default/first/even)
        $headerBlocks = [];
        $watermarkText = null;
        foreach ($pkg->headers as $partPath => $headerDoc) {
            // Сначала вытащить watermark и удалить из DOM, чтобы он не
            // попал в headerBlocks как обычный paragraph.
            $watermarkText ??= (new WatermarkExtractor)->extract($headerDoc);

            $headerRoot = $headerDoc->documentElement;
            if (! $headerRoot instanceof \DOMElement) {
                continue;
            }
            $hImg = new ImageReader($pkg, $partPath);
            $hReader = new BodyReader($styles, $numbering, $hImg, $pkg, $partPath);
            $headerBlocks = $hReader->read($headerRoot);
            break;
        }

        // Footers
        $footerBlocks = [];
        foreach ($pkg->footers as $partPath => $footerDoc) {
            $footerRoot = $footerDoc->documentElement;
            if (! $footerRoot instanceof \DOMElement) {
                continue;
            }
            $fImg = new ImageReader($pkg, $partPath);
            $fReader = new BodyReader($styles, $numbering, $fImg, $pkg, $partPath);
            $footerBlocks = $fReader->read($footerRoot);
            break;
        }

        return new Document(
            section: new Section(
                body: $this->ensureBlocks($body),
                header: $this->ensureBlocks($headerBlocks),
                footer: $this->ensureBlocks($footerBlocks),
                pageSetup: $pageSetup,
            ),
            watermarkText: $watermarkText,
        );
    }

    /**
     * @param  list<mixed>  $items
     * @return list<BlockElement>
     */
    private function ensureBlocks(array $items): array
    {
        return array_values(array_filter(
            $items,
            fn ($i): bool => $i instanceof BlockElement,
        ));
    }
}
