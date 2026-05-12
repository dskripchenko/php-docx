<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Writer;

use Dskripchenko\PhpDocx\Document;
use Dskripchenko\PhpDocx\Exception\DocxException;
use Dskripchenko\PhpDocx\Style\Orientation;
use Dskripchenko\PhpDocx\Style\PageSetup;
use Dskripchenko\PhpDocx\Style\StyleRegistry;

/**
 * Writer Document → DOCX (Word2007 format).
 *
 * Pipeline:
 *  1. BodyXmlBuilder рендерит section.body в XML, попутно регистрирует
 *     image/hyperlink rels через RelationshipManager.
 *  2. document.xml = <w:document>…<w:body>{rendered}</w:body><w:sectPr/></w:document>
 *  3. Rels из RelationshipManager → word/_rels/document.xml.rels
 *  4. Media файлы → word/media/imageN.ext
 *  5. Расширения content-types для image extensions → [Content_Types].xml
 *
 * Phase 3: body content rendering (paragraphs/runs/tables/HR/PageBreak).
 * Phase 4: image embedding (уже работает через RelationshipManager).
 * Phase 5a: headers/footers + watermark.
 * Phase 5b: lists + numbering.xml.
 * Phase 5c: styles.xml + StyleRegistry.
 */
final class Word2007Writer
{
    public function __construct(
        private readonly StyleRegistry $styles = new StyleRegistry,
    ) {}

    public function write(Document $document): string
    {
        $section = $document->section;
        // Если caller передал пустой registry — используем defaults
        // (Heading1..6 + ListParagraph) чтобы DOCX был самодостаточным.
        $styles = $this->styles->isEmpty() ? StyleRegistry::defaults() : $this->styles;
        $rels = new RelationshipManager;
        $builder = new BodyXmlBuilder($rels);
        $bodyXml = $builder->render($section->body);

        // Header XML. Watermark — отдельный raw-XML префикс (VML shape с
        // абсолютным позиционированием, rotation 315°, behind content).
        $headerBlocks = $section->header;
        $watermarkXml = ($document->watermarkText !== null && $document->watermarkText !== '')
            ? $this->watermarkXml($document->watermarkText)
            : '';

        $headerRId = null;
        $headerXml = null;
        if ($headerBlocks !== [] || $watermarkXml !== '') {
            $headerXml = $this->renderHeaderFooterXml($headerBlocks, $rels, isHeader: true, prefixXml: $watermarkXml);
            $headerRId = $rels->registerHeaderFooter('header1.xml', isHeader: true);
        }

        $footerRId = null;
        $footerXml = null;
        if ($section->footer !== []) {
            $footerXml = $this->renderHeaderFooterXml($section->footer, $rels, isHeader: false);
            $footerRId = $rels->registerHeaderFooter('footer1.xml', isHeader: false);
        }

        // Numbering part (Phase 5b) — генерим только если ListNode использовался.
        $numberingXml = null;
        if ($builder->usesNumbering()) {
            $numberingXml = (new NumberingXmlBuilder)->render();
            $rels->registerNumbering();
        }

        // Styles part (Phase 5c) — всегда генерим (с defaults как минимум).
        $stylesXml = (new StylesXmlBuilder($styles))->render();
        $rels->registerStyles();

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
            hasHeader: $headerXml !== null,
            hasFooter: $footerXml !== null,
            hasNumbering: $numberingXml !== null,
            hasStyles: true,
        ));
        $zip->addFromString('_rels/.rels', $this->renderRootRels());
        $zip->addFromString('word/document.xml', $this->renderDocumentXml($section->pageSetup, $bodyXml, $headerRId, $footerRId));
        $zip->addFromString('word/_rels/document.xml.rels', $this->renderDocumentRels($rels));

        if ($headerXml !== null) {
            $zip->addFromString('word/header1.xml', $headerXml);
        }
        if ($footerXml !== null) {
            $zip->addFromString('word/footer1.xml', $footerXml);
        }
        if ($numberingXml !== null) {
            $zip->addFromString('word/numbering.xml', $numberingXml);
        }
        $zip->addFromString('word/styles.xml', $stylesXml);

        foreach ($rels->mediaFiles() as $path => $binary) {
            $zip->addFromString($path, $binary);
        }

        $zip->close();

        $contents = (string) file_get_contents($tmpFile);
        @unlink($tmpFile);

        return $contents;
    }

    /**
     * VML watermark: `<v:shape type="#_x0000_t136">` с textpath, rotation 315°,
     * 50% opacity, absolute-position center. Стандартный Word-паттерн.
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
    private function renderHeaderFooterXml(array $blocks, RelationshipManager $rels, bool $isHeader, string $prefixXml = ''): string
    {
        // Используем тот же builder с тем же rels-manager (картинки/links
        // в header могут регистрировать rels в общем document-rels файле).
        $contentBuilder = new BodyXmlBuilder($rels);
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

    private function renderContentTypes(
        RelationshipManager $rels,
        bool $hasHeader = false,
        bool $hasFooter = false,
        bool $hasNumbering = false,
        bool $hasStyles = false,
    ): string {
        $defaults = '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>';

        foreach ($rels->contentTypeExtensions() as $ext => $mime) {
            $defaults .= '<Default Extension="'.XmlEscape::attr($ext).'" ContentType="'.XmlEscape::attr($mime).'"/>';
        }

        $overrides = '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>';
        if ($hasHeader) {
            $overrides .= '<Override PartName="/word/header1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml"/>';
        }
        if ($hasFooter) {
            $overrides .= '<Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/>';
        }
        if ($hasNumbering) {
            $overrides .= '<Override PartName="/word/numbering.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml"/>';
        }
        if ($hasStyles) {
            $overrides .= '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .$defaults
            .$overrides
            .'</Types>';
    }

    private function renderRootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            .'</Relationships>';
    }

    private function renderDocumentRels(RelationshipManager $rels): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($rels->relationships() as $r) {
            $xml .= '<Relationship Id="'.$r['id'].'" Type="'.$r['type'].'" Target="'.XmlEscape::attr($r['target']).'"';
            if (isset($r['targetMode'])) {
                $xml .= ' TargetMode="'.$r['targetMode'].'"';
            }
            $xml .= '/>';
        }
        $xml .= '</Relationships>';

        return $xml;
    }

    private function renderDocumentXml(PageSetup $ps, string $bodyXml, ?string $headerRId = null, ?string $footerRId = null): string
    {
        [$w, $h] = $ps->orientation === Orientation::Portrait
            ? [$ps->paperSize->widthTwips(), $ps->paperSize->heightTwips()]
            : [$ps->paperSize->heightTwips(), $ps->paperSize->widthTwips()];
        $orient = $ps->orientation->value;

        $sectPrInner = '';
        if ($headerRId !== null) {
            $sectPrInner .= '<w:headerReference w:type="default" r:id="'.$headerRId.'"/>';
        }
        if ($footerRId !== null) {
            $sectPrInner .= '<w:footerReference w:type="default" r:id="'.$footerRId.'"/>';
        }
        $sectPrInner .= '<w:pgSz w:w="'.$w.'" w:h="'.$h.'" w:orient="'.$orient.'"/>'
            .'<w:pgMar w:top="'.$ps->marginTopTwips.'" w:right="'.$ps->marginRightTwips.'" w:bottom="'.$ps->marginBottomTwips.'" w:left="'.$ps->marginLeftTwips.'" w:header="'.$ps->headerOffsetTwips.'" w:footer="'.$ps->footerOffsetTwips.'"/>';

        $sectPr = '<w:sectPr>'.$sectPrInner.'</w:sectPr>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document'
            .' xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"'
            .' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"'
            .' xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"'
            .' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
            .' xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"'
            .'>'
            .'<w:body>'.$bodyXml.$sectPr.'</w:body>'
            .'</w:document>';
    }
}
