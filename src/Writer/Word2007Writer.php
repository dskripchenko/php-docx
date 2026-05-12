<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Writer;

use Dskripchenko\PhpDocx\Document;
use Dskripchenko\PhpDocx\Exception\DocxException;
use Dskripchenko\PhpDocx\Style\Orientation;
use Dskripchenko\PhpDocx\Style\PageSetup;

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
    public function write(Document $document): string
    {
        $section = $document->section;
        $rels = new RelationshipManager;
        $builder = new BodyXmlBuilder($rels);
        $bodyXml = $builder->render($section->body);

        // Header/footer XML (опционально). Префиксуем watermark если задан.
        $headerBlocks = $section->header;
        if ($document->watermarkText !== null && $document->watermarkText !== '') {
            // Watermark — leading параграф большим серым центрированным текстом.
            // Не реальный VML-watermark, но cross-renderer'ный и читаемый.
            $headerBlocks = array_merge(
                [$this->watermarkParagraph($document->watermarkText)],
                $headerBlocks,
            );
        }

        $headerRId = null;
        $headerXml = null;
        if ($headerBlocks !== []) {
            $headerXml = $this->renderHeaderFooterXml($headerBlocks, $rels, isHeader: true);
            $headerRId = $rels->registerHeaderFooter('header1.xml', isHeader: true);
        }

        $footerRId = null;
        $footerXml = null;
        if ($section->footer !== []) {
            $footerXml = $this->renderHeaderFooterXml($section->footer, $rels, isHeader: false);
            $footerRId = $rels->registerHeaderFooter('footer1.xml', isHeader: false);
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'docx-');
        if ($tmpFile === false) {
            throw new DocxException('Не удалось создать temp-файл для DOCX.');
        }

        $zip = new \ZipArchive;
        if ($zip->open($tmpFile, \ZipArchive::OVERWRITE) !== true) {
            @unlink($tmpFile);
            throw new DocxException('Не удалось открыть ZipArchive для записи.');
        }

        $zip->addFromString('[Content_Types].xml', $this->renderContentTypes($rels, hasHeader: $headerXml !== null, hasFooter: $footerXml !== null));
        $zip->addFromString('_rels/.rels', $this->renderRootRels());
        $zip->addFromString('word/document.xml', $this->renderDocumentXml($section->pageSetup, $bodyXml, $headerRId, $footerRId));
        $zip->addFromString('word/_rels/document.xml.rels', $this->renderDocumentRels($rels));

        if ($headerXml !== null) {
            $zip->addFromString('word/header1.xml', $headerXml);
        }
        if ($footerXml !== null) {
            $zip->addFromString('word/footer1.xml', $footerXml);
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
     * Watermark — простой большой серый центрированный текст. Эффективно
     * заменяет реальный VML-watermark в большинстве случаев и совместим
     * со всеми OOXML-рендерерами.
     */
    private function watermarkParagraph(string $text): \Dskripchenko\PhpDocx\Element\Paragraph
    {
        return new \Dskripchenko\PhpDocx\Element\Paragraph(
            children: [new \Dskripchenko\PhpDocx\Element\Run(
                text: $text,
                style: new \Dskripchenko\PhpDocx\Style\RunStyle(
                    sizeHalfPoints: 96,  // 48pt
                    color: 'd0d0d0',
                    bold: true,
                ),
            )],
            style: new \Dskripchenko\PhpDocx\Style\ParagraphStyle(
                alignment: \Dskripchenko\PhpDocx\Style\Alignment::Center,
            ),
        );
    }

    /**
     * @param  list<\Dskripchenko\PhpDocx\Element\BlockElement>  $blocks
     */
    private function renderHeaderFooterXml(array $blocks, RelationshipManager $rels, bool $isHeader): string
    {
        // Используем тот же builder с тем же rels-manager (картинки/links
        // в header могут регистрировать rels в общем document-rels файле).
        $contentBuilder = new BodyXmlBuilder($rels);
        $content = $contentBuilder->render($blocks);
        $tag = $isHeader ? 'w:hdr' : 'w:ftr';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<'.$tag
            .' xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"'
            .' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"'
            .' xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"'
            .' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
            .' xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"'
            .'>'
            .$content
            .'</'.$tag.'>';
    }

    private function renderContentTypes(RelationshipManager $rels, bool $hasHeader = false, bool $hasFooter = false): string
    {
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
