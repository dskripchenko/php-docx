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
        $numbering = new NumberingXmlBuilder;
        $builder = new BodyXmlBuilder($rels, $numbering);
        $bodyXml = $builder->render($section->body);

        // Multi-header/footer: собираем все типы (default/first/even).
        // Watermark идёт ТОЛЬКО на default header (если default отсутствует —
        // создаём его пустым с watermark prefix).
        $watermarkXml = ($document->watermarkText !== null && $document->watermarkText !== '')
            ? $this->watermarkXml($document->watermarkText)
            : '';

        $headers = $section->allHeaders(); // type → blocks
        $footers = $section->allFooters();

        if ($watermarkXml !== '' && ! isset($headers['default'])) {
            // Watermark требует default-header'а — создаём пустой.
            $headers['default'] = [];
        }

        /** @var array<string, array{rId:string, xml:string, partName:string}> $headerParts */
        $headerParts = [];
        $headerCounter = 0;
        foreach ($headers as $type => $blocks) {
            $headerCounter++;
            $partName = 'header'.$headerCounter.'.xml';
            $prefix = $type === 'default' ? $watermarkXml : '';
            $xml = $rels->forPart(
                $partName,
                fn (): string => $this->renderHeaderFooterXml($blocks, $rels, $numbering, isHeader: true, prefixXml: $prefix),
            );
            $rId = $rels->registerHeaderFooter($partName, isHeader: true);
            $headerParts[$type] = ['rId' => $rId, 'xml' => $xml, 'partName' => $partName];
        }

        /** @var array<string, array{rId:string, xml:string, partName:string}> $footerParts */
        $footerParts = [];
        $footerCounter = 0;
        foreach ($footers as $type => $blocks) {
            $footerCounter++;
            $partName = 'footer'.$footerCounter.'.xml';
            $xml = $rels->forPart(
                $partName,
                fn (): string => $this->renderHeaderFooterXml($blocks, $rels, $numbering, isHeader: false),
            );
            $rId = $rels->registerHeaderFooter($partName, isHeader: false);
            $footerParts[$type] = ['rId' => $rId, 'xml' => $xml, 'partName' => $partName];
        }

        $hasFirstPage = $section->hasFirstPageHeaderOrFooter();
        $hasEvenPage = $section->hasEvenPageHeaderOrFooter();

        // Numbering part (Phase 5b)
        $numberingXml = null;
        if ($numbering->isUsed()) {
            $numberingXml = $numbering->render();
            $rels->registerNumbering();
        }

        // Settings part — нужен только если есть even-headers/footers
        // (для <w:evenAndOddHeaders/>).
        $settingsXml = null;
        if ($hasEvenPage) {
            $settingsXml = (new SettingsXmlBuilder(evenAndOddHeaders: true))->render();
            $rels->registerSettings();
        }

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
            headerPartNames: array_map(fn ($info) => $info['partName'], $headerParts),
            footerPartNames: array_map(fn ($info) => $info['partName'], $footerParts),
            hasNumbering: $numberingXml !== null,
            hasStyles: true,
            hasSettings: $settingsXml !== null,
        ));
        $zip->addFromString('_rels/.rels', $this->renderRootRels());
        $zip->addFromString('word/document.xml', $this->renderDocumentXml(
            $section->pageSetup,
            $bodyXml,
            headerRefs: array_map(fn ($info) => $info['rId'], $headerParts),
            footerRefs: array_map(fn ($info) => $info['rId'], $footerParts),
            hasFirstPage: $hasFirstPage,
        ));
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

        // Ссылка внутри колонтитула разрешается относительно rels ЕГО части,
        // а не документа: без своего файла Word не находит картинку и
        // объявляет документ повреждённым.
        foreach ($rels->partRelationships() as $partName => $partRels) {
            $zip->addFromString('word/_rels/'.$partName.'.rels', $this->renderRelsFile($partRels));
        }

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
    private function renderHeaderFooterXml(array $blocks, RelationshipManager $rels, NumberingXmlBuilder $numbering, bool $isHeader, string $prefixXml = ''): string
    {
        // Используем тот же builder с теми же rels-manager и numbering-builder
        // (картинки/links/lists в header могут регистрировать ресурсы в
        // общем document-rels/numbering файле).
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
     * @param  list<string>  $headerPartNames  Имена header parts (header1.xml, ...)
     * @param  list<string>  $footerPartNames
     */
    private function renderContentTypes(
        RelationshipManager $rels,
        array $headerPartNames = [],
        array $footerPartNames = [],
        bool $hasNumbering = false,
        bool $hasStyles = false,
        bool $hasSettings = false,
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

    /**
     * @param  array<string, string>  $headerRefs  type → rId
     * @param  array<string, string>  $footerRefs  type → rId
     */
    private function renderDocumentXml(
        PageSetup $ps,
        string $bodyXml,
        array $headerRefs = [],
        array $footerRefs = [],
        bool $hasFirstPage = false,
    ): string {
        [$w, $h] = $ps->orientation === Orientation::Portrait
            ? [$ps->paperSize->widthTwips(), $ps->paperSize->heightTwips()]
            : [$ps->paperSize->heightTwips(), $ps->paperSize->widthTwips()];
        $orient = $ps->orientation->value;

        $sectPrInner = '';
        foreach ($headerRefs as $type => $rId) {
            $sectPrInner .= '<w:headerReference w:type="'.$type.'" r:id="'.$rId.'"/>';
        }
        foreach ($footerRefs as $type => $rId) {
            $sectPrInner .= '<w:footerReference w:type="'.$type.'" r:id="'.$rId.'"/>';
        }
        $sectPrInner .= '<w:pgSz w:w="'.$w.'" w:h="'.$h.'" w:orient="'.$orient.'"/>'
            .'<w:pgMar w:top="'.$ps->marginTopTwips.'" w:right="'.$ps->marginRightTwips.'" w:bottom="'.$ps->marginBottomTwips.'" w:left="'.$ps->marginLeftTwips.'" w:header="'.$ps->headerOffsetTwips.'" w:footer="'.$ps->footerOffsetTwips.'" w:gutter="0"/>';

        // Word требует <w:titlePg/> если first-page header/footer задан —
        // иначе ссылка type="first" игнорируется.
        if ($hasFirstPage) {
            $sectPrInner .= '<w:titlePg/>';
        }

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
