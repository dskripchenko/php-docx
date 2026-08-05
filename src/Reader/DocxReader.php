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
 * Header/footer типы (default/first/even) детектируются по
 * `<w:headerReference w:type="X" r:id="Y">` в sectPr — pkg->headers
 * mapping (path→DOM) сам по себе типа не несёт.
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
        $footnotes = new FootnoteReader($pkg->footnotesXml);
        $bodyReader = new BodyReader(
            $styles,
            $numbering,
            $bodyImgReader,
            $pkg,
            $pkg->documentPartPath,
            $footnotes,
        );
        $body = $bodyReader->read($bodyEl);
        $pageSetup = (new SectionReader)->readPageSetup($bodyEl);

        // Маппинг path → type через sectPr-references.
        $headerTypes = $this->resolveHeaderTypes($bodyEl, $pkg, isFooter: false);
        $footerTypes = $this->resolveHeaderTypes($bodyEl, $pkg, isFooter: true);

        $headersBy = [
            'default' => [],
            'first' => [],
            'even' => [],
        ];
        $footersBy = [
            'default' => [],
            'first' => [],
            'even' => [],
        ];
        $watermarkText = null;

        foreach ($pkg->headers as $partPath => $headerDoc) {
            $type = $headerTypes[$partPath] ?? 'default';
            // Watermark — обычно в default header'е; экстрагируем из любого
            // (первый найденный побеждает) чтобы не дублировать в HTML.
            $watermarkText ??= (new WatermarkExtractor)->extract($headerDoc);

            $headerRoot = $headerDoc->documentElement;
            if (! $headerRoot instanceof \DOMElement) {
                continue;
            }
            $hImg = new ImageReader($pkg, $partPath);
            $hReader = new BodyReader($styles, $numbering, $hImg, $pkg, $partPath);
            $headersBy[$type] = $this->ensureBlocks($hReader->read($headerRoot));
        }

        foreach ($pkg->footers as $partPath => $footerDoc) {
            $type = $footerTypes[$partPath] ?? 'default';
            $footerRoot = $footerDoc->documentElement;
            if (! $footerRoot instanceof \DOMElement) {
                continue;
            }
            $fImg = new ImageReader($pkg, $partPath);
            $fReader = new BodyReader($styles, $numbering, $fImg, $pkg, $partPath);
            $footersBy[$type] = $this->ensureBlocks($fReader->read($footerRoot));
        }

        return new Document(
            section: new Section(
                body: $this->ensureBlocks($body),
                header: $headersBy['default'],
                footer: $footersBy['default'],
                pageSetup: $pageSetup,
                firstHeader: $headersBy['first'],
                firstFooter: $footersBy['first'],
                evenHeader: $headersBy['even'],
                evenFooter: $footersBy['even'],
            ),
            watermarkText: $watermarkText,
        );
    }

    /**
     * Парсит `<w:headerReference>`/`<w:footerReference>` в sectPr и
     * возвращает map<partPath, type>.
     *
     * @return array<string, string>
     */
    private function resolveHeaderTypes(\DOMElement $body, DocxPackage $pkg, bool $isFooter): array
    {
        $tagName = $isFooter ? 'footerReference' : 'headerReference';
        $refs = $body->getElementsByTagNameNS(OoxmlNs::W, $tagName);
        $out = [];
        foreach ($refs as $ref) {
            if (! $ref instanceof \DOMElement) {
                continue;
            }
            $type = $ref->getAttributeNS(OoxmlNs::W, 'type');
            if ($type === '') {
                $type = 'default';
            }
            $rId = $ref->getAttributeNS(OoxmlNs::R, 'id');
            if ($rId === '') {
                continue;
            }
            try {
                $rel = $pkg->resolveDocumentRel($rId);
            } catch (\Throwable) {
                continue;
            }
            $absPath = $pkg->resolveMediaPath($pkg->documentPartPath, $rel->target);
            $out[$absPath] = $type;
        }

        return $out;
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
