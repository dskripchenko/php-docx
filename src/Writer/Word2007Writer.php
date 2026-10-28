<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Writer;

use Dskripchenko\PhpDocx\Document;
use Dskripchenko\PhpDocx\Exception\DocxException;
use Dskripchenko\PhpDocx\Section;
use Dskripchenko\PhpDocx\Style\SectionStart;
use Dskripchenko\PhpDocx\Style\StyleRegistry;

/**
 * The writer of a Document into a DOCX (the Word2007 format).
 *
 * The pipeline:
 *  1. BodyXmlBuilder renders section.body into XML, registering the image and
 *     hyperlink relationships through the RelationshipManager along the way.
 *  2. document.xml = <w:document>...<w:body>{rendered}</w:body><w:sectPr/></w:document>
 *  3. The relationships from the RelationshipManager →
 *     word/_rels/document.xml.rels
 *  4. The media files → word/media/imageN.ext
 *  5. The content-type entries for the image extensions → [Content_Types].xml
 *
 * Phase 3: rendering the body content (paragraphs/runs/tables/HR/PageBreak).
 * Phase 4: embedding the images (already works through the RelationshipManager).
 * Phase 5a: the headers and footers plus the watermark.
 * Phase 5b: the lists plus numbering.xml.
 * Phase 5c: styles.xml plus the StyleRegistry.
 */
final class Word2007Writer
{
    public function __construct(
        private readonly StyleRegistry $styles = new StyleRegistry,
    ) {}

    public function write(Document $document): string
    {
        $sections = $document->sections();
        $last = count($sections) - 1;
        // When the caller passed an empty registry we use the defaults
        // (Heading1..6 plus ListParagraph) so that the DOCX is self-contained.
        $styles = $this->styles->isEmpty() ? StyleRegistry::defaults() : $this->styles;
        $rels = new RelationshipManager;
        $numbering = new NumberingXmlBuilder;
        $builder = new BodyXmlBuilder($rels, $numbering);
        // Every section but the last ends with its <w:sectPr> in the pPr of
        // its last paragraph. The bodies are rendered first, as for a single
        // section, so the relationship ids come out in the same order; each
        // sectPr is filled in once the header and footer parts have their ids.
        $bodyXml = '';
        foreach ($sections as $i => $section) {
            $bodyXml .= $builder->render($section->body, $i < $last ? self::sectPrSlot($i) : '');
        }

        // Multiple headers and footers: we gather every kind
        // (default/first/even) of every section. The watermark goes ONLY onto
        // default headers (when the first section has no default one we create
        // an empty one with the watermark prefix; later sections inherit it).
        $watermarkXml = ($document->watermarkText !== null && $document->watermarkText !== '')
            ? $this->watermarkXml($document->watermarkText)
            : '';

        /** @var array<string, array{rId:string, xml:string, partName:string}> $headerParts */
        $headerParts = [];
        /** @var array<string, array{rId:string, xml:string, partName:string}> $footerParts */
        $footerParts = [];
        /** @var list<array<string, string>> $headerRefs per section: type → rId */
        $headerRefs = [];
        /** @var list<array<string, string>> $footerRefs per section: type → rId */
        $footerRefs = [];
        foreach ($sections as $i => $section) {
            $headers = $section->allHeaders(); // type → blocks
            if ($i === 0 && $watermarkXml !== '' && ! isset($headers['default'])) {
                // The watermark needs a default header — we create an empty one.
                $headers['default'] = [];
            }
            $headerRefs[$i] = [];
            foreach ($headers as $type => $blocks) {
                $partName = 'header'.(count($headerParts) + 1).'.xml';
                $prefix = $type === 'default' ? $watermarkXml : '';
                $xml = $rels->forPart(
                    $partName,
                    fn (): string => $this->renderHeaderFooterXml($blocks, $rels, $numbering, isHeader: true, prefixXml: $prefix),
                );
                $rId = $rels->registerHeaderFooter($partName, isHeader: true);
                $headerParts[$partName] = ['rId' => $rId, 'xml' => $xml, 'partName' => $partName];
                $headerRefs[$i][$type] = $rId;
            }
        }
        foreach ($sections as $i => $section) {
            $footerRefs[$i] = [];
            foreach ($section->allFooters() as $type => $blocks) {
                $partName = 'footer'.(count($footerParts) + 1).'.xml';
                $xml = $rels->forPart(
                    $partName,
                    fn (): string => $this->renderHeaderFooterXml($blocks, $rels, $numbering, isHeader: false),
                );
                $rId = $rels->registerHeaderFooter($partName, isHeader: false);
                $footerParts[$partName] = ['rId' => $rId, 'xml' => $xml, 'partName' => $partName];
                $footerRefs[$i][$type] = $rId;
            }
        }

        $hasEvenPage = false;
        $sectPrs = [];
        foreach ($sections as $i => $section) {
            $hasEvenPage = $hasEvenPage || $section->hasEvenPageHeaderOrFooter();
            $sectPrs[$i] = $this->renderSectPr($section, $headerRefs[$i], $footerRefs[$i], isFirst: $i === 0);
        }
        $finalSectPr = $sectPrs[$last];
        unset($sectPrs[$last]);
        $bodyXml = strtr($bodyXml, array_combine(array_map(self::sectPrSlot(...), array_keys($sectPrs)), $sectPrs));

        // Numbering part (Phase 5b)
        $numberingXml = null;
        if ($numbering->isUsed()) {
            $numberingXml = $numbering->render();
            $rels->registerNumbering();
        }

        // The settings part is needed only when there are even headers or
        // footers (for the <w:evenAndOddHeaders/>).
        $settingsXml = null;
        if ($hasEvenPage) {
            $settingsXml = (new SettingsXmlBuilder(evenAndOddHeaders: true))->render();
            $rels->registerSettings();
        }

        $stylesXml = (new StylesXmlBuilder($styles))->render();
        $rels->registerStyles();

        // The core properties are written only when there is something to
        // write — an empty properties part is worse than none, it is just extra
        // noise in the package.
        $core = $document->coreProperties;
        $coreXml = $core !== null && ! $core->isEmpty()
            ? (new CorePropertiesXmlBuilder($core))->render()
            : null;

        $tmpFile = tempnam(sys_get_temp_dir(), 'docx-');
        if ($tmpFile === false) {
            throw new DocxException('Не удалось создать temp-файл для DOCX.');
        }

        $zip = new \ZipArchive;
        if ($zip->open($tmpFile, \ZipArchive::OVERWRITE) !== true) {
            @unlink($tmpFile);
            throw new DocxException('Не удалось открыть ZipArchive для записи.');
        }

        $zip->addFromString('[Content_Types].xml', $this->renderContentTypes(
            $rels,
            headerPartNames: array_map(fn ($info) => $info['partName'], $headerParts),
            footerPartNames: array_map(fn ($info) => $info['partName'], $footerParts),
            hasNumbering: $numberingXml !== null,
            hasStyles: true,
            hasSettings: $settingsXml !== null,
            hasCoreProperties: $coreXml !== null,
        ));
        $zip->addFromString('_rels/.rels', $this->renderRootRels($coreXml !== null));
        if ($coreXml !== null) {
            $zip->addFromString('docProps/core.xml', $coreXml);
        }
        $zip->addFromString('word/document.xml', $this->renderDocumentXml($bodyXml, $finalSectPr));
        $zip->addFromString('word/_rels/document.xml.rels', $this->renderDocumentRels($rels));

        foreach ($headerParts as $info) {
            $zip->addFromString('word/'.$info['partName'], $info['xml']);
        }
        foreach ($footerParts as $info) {
            $zip->addFromString('word/'.$info['partName'], $info['xml']);
        }
        if ($numberingXml !== null) {
            $zip->addFromString('word/numbering.xml', $numberingXml);
        }
        if ($settingsXml !== null) {
            $zip->addFromString('word/settings.xml', $settingsXml);
        }
        $zip->addFromString('word/styles.xml', $stylesXml);

        // A reference inside a header is resolved against the rels of ITS part
        // rather than the document's: without a file of its own Word does not
        // find the image and declares the document corrupt.
        foreach ($rels->partRelationships() as $partName => $partRels) {
            $zip->addFromString('word/_rels/'.$partName.'.rels', $this->renderRelsFile($partRels));
        }

        foreach ($rels->mediaFiles() as $path => $binary) {
            $zip->addFromString($path, $binary);
        }

        $zip->close();

        $contents = (string) file_get_contents($tmpFile);
        @unlink($tmpFile);

        // ZipArchive stamps every entry with the current time: without a
        // fixed time the same document gives different bytes on every write.
        return ZipTimestamp::apply($contents, $core?->modified);
    }

    /**
     * The VML watermark: a `<v:shape type="#_x0000_t136">` with a textpath, a
     * rotation of 315°, 50% opacity and an absolute centred position. The
     * standard Word pattern.
     */
    private function watermarkXml(string $text): string
    {
        $escaped = XmlEscape::attr($text);

        return '<w:p><w:r><w:pict>'
            .'<v:shapetype id="_x0000_t136" coordsize="21600,21600" o:spt="136" adj="10800"'
            .' path="m@7,l@8,m@5,21600l@6,21600e">'
            .'<v:formulas>'
            .'<v:f eqn="sum #0 0 10800"/><v:f eqn="prod #0 2 1"/>'
            .'<v:f eqn="sum 21600 0 @1"/><v:f eqn="sum 0 0 @2"/>'
            .'<v:f eqn="sum 21600 0 @3"/><v:f eqn="if @0 @3 0"/>'
            .'<v:f eqn="if @0 21600 @1"/><v:f eqn="if @0 0 @2"/>'
            .'<v:f eqn="if @0 @4 21600"/><v:f eqn="mid @5 @6"/>'
            .'<v:f eqn="mid @8 @5"/><v:f eqn="mid @7 @8"/>'
            .'<v:f eqn="mid @6 @7"/><v:f eqn="sum @6 0 @5"/>'
            .'</v:formulas>'
            .'<v:path o:extrusionok="f" gradientshapeok="t" o:connecttype="custom"'
            .' o:connectlocs="@9,0;@10,10800;@11,21600;@12,10800"'
            .' o:connectangles="270,180,90,0"/>'
            .'<v:textpath on="t" fitshape="t"/>'
            .'<o:lock v:ext="edit" text="t" shapetype="t"/>'
            .'</v:shapetype>'
            .'<v:shape id="WordPictureWatermark" o:spid="_x0000_s2049" type="#_x0000_t136"'
            .' style="position:absolute;margin-left:0;margin-top:0;width:468pt;height:234pt;'
            .'rotation:315;z-index:251654144;mso-position-horizontal:center;'
            .'mso-position-horizontal-relative:margin;mso-position-vertical:center;'
            .'mso-position-vertical-relative:margin"'
            .' fillcolor="silver" stroked="f">'
            .'<v:fill opacity=".5"/>'
            .'<v:textpath style="font-family:&quot;Calibri&quot;;font-size:1pt" string="'.$escaped.'"/>'
            .'</v:shape>'
            .'</w:pict></w:r></w:p>';
    }

    /**
     * @param  list<\Dskripchenko\PhpDocx\Element\BlockElement>  $blocks
     */
    private function renderHeaderFooterXml(array $blocks, RelationshipManager $rels, NumberingXmlBuilder $numbering, bool $isHeader, string $prefixXml = ''): string
    {
        // We use the same builder with the same relationship manager and
        // numbering builder (the images, links and lists of a header may
        // register resources in the shared document rels or numbering file).
        $contentBuilder = new BodyXmlBuilder($rels, $numbering);
        $content = $prefixXml.$contentBuilder->render($blocks);
        $tag = $isHeader ? 'w:hdr' : 'w:ftr';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<'.$tag
            .' xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"'
            .' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"'
            .' xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"'
            .' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
            .' xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"'
            .' xmlns:v="urn:schemas-microsoft-com:vml"'
            .' xmlns:o="urn:schemas-microsoft-com:office:office"'
            .'>'
            .$content
            .'</'.$tag.'>';
    }

    /**
     * @param  list<string>  $headerPartNames  The names of the header parts (header1.xml, ...)
     * @param  list<string>  $footerPartNames
     */
    private function renderContentTypes(
        RelationshipManager $rels,
        array $headerPartNames = [],
        array $footerPartNames = [],
        bool $hasNumbering = false,
        bool $hasStyles = false,
        bool $hasSettings = false,
        bool $hasCoreProperties = false,
    ): string {
        $defaults = '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>';

        foreach ($rels->contentTypeExtensions() as $ext => $mime) {
            $defaults .= '<Default Extension="'.XmlEscape::attr($ext).'" ContentType="'.XmlEscape::attr($mime).'"/>';
        }

        $overrides = '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>';
        foreach ($headerPartNames as $name) {
            $overrides .= '<Override PartName="/word/'.XmlEscape::attr($name).'" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml"/>';
        }
        foreach ($footerPartNames as $name) {
            $overrides .= '<Override PartName="/word/'.XmlEscape::attr($name).'" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/>';
        }
        if ($hasNumbering) {
            $overrides .= '<Override PartName="/word/numbering.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml"/>';
        }
        if ($hasStyles) {
            $overrides .= '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>';
        }
        if ($hasSettings) {
            $overrides .= '<Override PartName="/word/settings.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml"/>';
        }
        if ($hasCoreProperties) {
            $overrides .= '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .$defaults
            .$overrides
            .'</Types>';
    }

    /** An empty paragraph when the XML does not end with one (otherwise nothing). */
    private function trailingParagraph(string $xml): string
    {
        if ($xml === '' || str_ends_with($xml, '</w:p>') || str_ends_with($xml, '<w:p/>')) {
            return '';
        }

        return '<w:p/>';
    }

    private function renderRootRels(bool $hasCoreProperties = false): string
    {
        // The relationship to core.xml is declared HERE, in the root rels,
        // rather than in the document's: the properties belong to the package,
        // not to the main part. Declared in the wrong place, the part stays
        // invisible to Word.
        $core = $hasCoreProperties
            ? '<Relationship Id="rIdCore" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            : '';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            .$core
            .'</Relationships>';
    }

    private function renderDocumentRels(RelationshipManager $rels): string
    {
        return $this->renderRelsFile($rels->relationships());
    }

    /**
     * @param  list<array{id: string, type: string, target: string, targetMode?: string}>  $relationships
     */
    private function renderRelsFile(array $relationships): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($relationships as $r) {
            $xml .= '<Relationship Id="'.$r['id'].'" Type="'.$r['type'].'" Target="'.XmlEscape::attr($r['target']).'"';
            if (isset($r['targetMode'])) {
                $xml .= ' TargetMode="'.$r['targetMode'].'"';
            }
            $xml .= '/>';
        }
        $xml .= '</Relationships>';

        return $xml;
    }

    /** A placeholder for a section's sectPr in the body XML, filled in by write(). */
    private static function sectPrSlot(int $section): string
    {
        return '<!--php-docx:sectPr:'.$section.'-->';
    }

    /**
     * One section's `<w:sectPr>`: its header/footer references, where it
     * starts, the page size and margins, and the first-page flag.
     *
     * @param  array<string, string>  $headerRefs  type → rId
     * @param  array<string, string>  $footerRefs  type → rId
     */
    private function renderSectPr(Section $section, array $headerRefs, array $footerRefs, bool $isFirst): string
    {
        $ps = $section->pageSetup;
        $inner = '';
        foreach ($headerRefs as $type => $rId) {
            $inner .= '<w:headerReference w:type="'.$type.'" r:id="'.$rId.'"/>';
        }
        foreach ($footerRefs as $type => $rId) {
            $inner .= '<w:footerReference w:type="'.$type.'" r:id="'.$rId.'"/>';
        }
        // A new page is what a section does without a w:type; the first
        // section's start means nothing.
        if (! $isFirst && $section->start !== SectionStart::NextPage) {
            $inner .= '<w:type w:val="'.$section->start->value.'"/>';
        }
        $inner .= '<w:pgSz w:w="'.$ps->pageWidthTwips().'" w:h="'.$ps->pageHeightTwips().'" w:orient="'.$ps->effectiveOrientation()->value.'"/>'
            .'<w:pgMar w:top="'.$ps->marginTopTwips.'" w:right="'.$ps->marginRightTwips.'" w:bottom="'.$ps->marginBottomTwips.'" w:left="'.$ps->marginLeftTwips.'" w:header="'.$ps->headerOffsetTwips.'" w:footer="'.$ps->footerOffsetTwips.'" w:gutter="0"/>';

        // Word requires a <w:titlePg/> when a first-page header or footer is
        // set — otherwise the type="first" reference is ignored.
        if ($section->hasFirstPageHeaderOrFooter()) {
            $inner .= '<w:titlePg/>';
        }

        return '<w:sectPr>'.$inner.'</w:sectPr>';
    }

    private function renderDocumentXml(string $bodyXml, string $sectPr): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document'
            .' xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"'
            .' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"'
            .' xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"'
            .' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
            .' xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"'
            .'>'
            // The body, like a cell, must end with a paragraph: a document
            // whose last block is a table is opened by Word with an offer to
            // recover the content.
            .'<w:body>'.$bodyXml.$this->trailingParagraph($bodyXml).$sectPr.'</w:body>'
            .'</w:document>';
    }
}
