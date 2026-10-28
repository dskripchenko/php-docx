<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Reader;

use Dskripchenko\PhpDocx\Document;
use Dskripchenko\PhpDocx\Element\BlockElement;
use Dskripchenko\PhpDocx\Exception\DocxException;
use Dskripchenko\PhpDocx\Section;

/**
 * Phase 8 — the high-level facade.
 *
 * DOCX bytes → Document (the AST). It coordinates every reader of phases 1-8.
 *
 * Header/footer types (default/first/even) are detected from
 * `<w:headerReference w:type="X" r:id="Y">` in sectPr — the pkg->headers
 * mapping (path→DOM) carries no type on its own.
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
        // The body splits into sections at each <w:sectPr> in a paragraph's
        // pPr; the last section's sectPr is the body's own (or, when Word put
        // it there, the last paragraph's).
        $sectionReader = new SectionReader;
        // Word shows the even-page parts only with <w:evenAndOddHeaders/> in
        // the settings, for every section.
        $usesEven = $pkg->settingsXml?->documentElement instanceof \DOMElement
            && self::onFlag($pkg->settingsXml->documentElement, 'evenAndOddHeaders');
        /** @var array<string, list<BlockElement>> $partBlocks header/footer part path → blocks, read once */
        $partBlocks = [];
        $watermarkText = null;
        foreach ($pkg->headers as $headerDoc) {
            // A watermark usually sits in the default header; extract it from any
            // one (the first found wins) so that HTML does not get duplicates.
            $watermarkText ??= (new WatermarkExtractor)->extract($headerDoc);
        }

        $sections = [];
        $groups = $this->splitSections($bodyEl);
        foreach ($groups as [$nodes, $sectPr]) {
            $container = $bodyEl->ownerDocument?->createElementNS(OoxmlNs::W, 'w:body');
            if (! $container instanceof \DOMElement) {
                throw new DocxException('Не удалось разобрать разделы word/document.xml.');
            }
            foreach ($nodes as $node) {
                $container->appendChild($node->cloneNode(true));
            }
            $body = $bodyReader->read($container);
            // Without a sectPr at all the references are looked for in the
            // whole body, as before sections were read one by one.
            $scope = $sectPr ?? (count($groups) === 1 ? $bodyEl : null);
            $headerTypes = $scope === null ? [] : $this->resolveHeaderTypes($scope, $pkg, isFooter: false);
            $footerTypes = $scope === null ? [] : $this->resolveHeaderTypes($scope, $pkg, isFooter: true);
            // Word shows the first-page parts only with <w:titlePg/>.
            $usesFirst = $sectPr instanceof \DOMElement && self::onFlag($sectPr, 'titlePg');
            $headersBy = $this->partsByType($headerTypes, $pkg->headers, $pkg, $styles, $numbering, $partBlocks);
            $footersBy = $this->partsByType($footerTypes, $pkg->footers, $pkg, $styles, $numbering, $partBlocks);

            $sections[] = new Section(
                body: $this->ensureBlocks($body),
                header: $headersBy['default'],
                footer: $footersBy['default'],
                pageSetup: $sectionReader->fromSectPr($sectPr),
                firstHeader: $usesFirst ? $headersBy['first'] : [],
                firstFooter: $usesFirst ? $footersBy['first'] : [],
                evenHeader: $usesEven ? $headersBy['even'] : [],
                evenFooter: $usesEven ? $footersBy['even'] : [],
                start: $sectionReader->start($sectPr),
            );
        }

        return new Document(
            section: $sections[0],
            watermarkText: $watermarkText,
            followingSections: array_slice($sections, 1),
        );
    }

    /**
     * The body's children cut into sections, each with the sectPr that ends it
     * (null when the document has none).
     *
     * @return non-empty-list<array{list<\DOMNode>, ?\DOMElement}>
     */
    private function splitSections(\DOMElement $body): array
    {
        $groups = [];
        $nodes = [];
        $bodySectPr = null;
        foreach ($body->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->namespaceURI === OoxmlNs::W && $child->localName === 'sectPr') {
                $bodySectPr = $child;
                continue;
            }
            $nodes[] = $child;
            $pPr = $child instanceof \DOMElement && $child->namespaceURI === OoxmlNs::W && $child->localName === 'p'
                ? OoxmlNs::firstChild($child, OoxmlNs::W, 'pPr')
                : null;
            $sectPr = $pPr === null ? null : OoxmlNs::firstChild($pPr, OoxmlNs::W, 'sectPr');
            if ($sectPr !== null) {
                $groups[] = [$nodes, $sectPr];
                $nodes = [];
            }
        }
        // What follows the last paragraph-level sectPr is the final section;
        // when nothing does and the body has no sectPr of its own, Word put
        // the final section's sectPr on the last paragraph.
        $rest = array_filter($nodes, static fn (\DOMNode $n): bool => $n instanceof \DOMElement);
        if ($groups === [] || $rest !== [] || $bodySectPr !== null) {
            $groups[] = [$nodes, $bodySectPr];
        }

        return $groups;
    }

    /**
     * A section's header or footer blocks by type, from the parts its sectPr
     * references.
     *
     * @param  array<string, string>  $types  part path → type
     * @param  array<string, \DOMDocument>  $docs  part path → DOM
     * @param  array<string, list<BlockElement>>  $cache  part path → blocks
     * @return array{default: list<BlockElement>, first: list<BlockElement>, even: list<BlockElement>}
     */
    private function partsByType(array $types, array $docs, DocxPackage $pkg, StylesResolver $styles, NumberingDefinitions $numbering, array &$cache): array
    {
        $by = ['default' => [], 'first' => [], 'even' => []];
        foreach ($docs as $partPath => $partDoc) {
            $type = $types[$partPath] ?? null;
            $root = $partDoc->documentElement;
            if ($type === null || ! $root instanceof \DOMElement || ! array_key_exists($type, $by)) {
                continue;
            }
            $cache[$partPath] ??= $this->ensureBlocks((new BodyReader($styles, $numbering, new ImageReader($pkg, $partPath), $pkg, $partPath))->read($root));
            $by[$type] = $cache[$partPath];
        }

        return $by;
    }

    /**
     * Parses `<w:headerReference>`/`<w:footerReference>` in sectPr and returns
     * a map<partPath, type>.
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

    /** An on/off element among the children: present and not `w:val="0|false|off"`. */
    private static function onFlag(\DOMElement $parent, string $name): bool
    {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->namespaceURI === OoxmlNs::W && $child->localName === $name) {
                return ! in_array(strtolower($child->getAttributeNS(OoxmlNs::W, 'val')), ['0', 'false', 'off'], true);
            }
        }

        return false;
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
