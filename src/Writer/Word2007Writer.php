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
        $rels = new RelationshipManager;
        $builder = new BodyXmlBuilder($rels);
        $bodyXml = $builder->render($document->section->body);

        $tmpFile = tempnam(sys_get_temp_dir(), 'docx-');
        if ($tmpFile === false) {
            throw new DocxException('Не удалось создать temp-файл для DOCX.');
        }

        $zip = new \ZipArchive;
        if ($zip->open($tmpFile, \ZipArchive::OVERWRITE) !== true) {
            @unlink($tmpFile);
            throw new DocxException('Не удалось открыть ZipArchive для записи.');
        }

        $zip->addFromString('[Content_Types].xml', $this->renderContentTypes($rels));
        $zip->addFromString('_rels/.rels', $this->renderRootRels());
        $zip->addFromString('word/document.xml', $this->renderDocumentXml($document->section->pageSetup, $bodyXml));
        $zip->addFromString('word/_rels/document.xml.rels', $this->renderDocumentRels($rels));

        foreach ($rels->mediaFiles() as $path => $binary) {
            $zip->addFromString($path, $binary);
        }

        $zip->close();

        $contents = (string) file_get_contents($tmpFile);
        @unlink($tmpFile);

        return $contents;
    }

    private function renderContentTypes(RelationshipManager $rels): string
    {
        $defaults = '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>';

        foreach ($rels->contentTypeExtensions() as $ext => $mime) {
            $defaults .= '<Default Extension="'.XmlEscape::attr($ext).'" ContentType="'.XmlEscape::attr($mime).'"/>';
        }

        $overrides = '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>';

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

    private function renderDocumentXml(PageSetup $ps, string $bodyXml): string
    {
        [$w, $h] = $ps->orientation === Orientation::Portrait
            ? [$ps->paperSize->widthTwips(), $ps->paperSize->heightTwips()]
            : [$ps->paperSize->heightTwips(), $ps->paperSize->widthTwips()];
        $orient = $ps->orientation->value;

        $sectPr = '<w:sectPr>'
            .'<w:pgSz w:w="'.$w.'" w:h="'.$h.'" w:orient="'.$orient.'"/>'
            .'<w:pgMar w:top="'.$ps->marginTopTwips.'" w:right="'.$ps->marginRightTwips.'" w:bottom="'.$ps->marginBottomTwips.'" w:left="'.$ps->marginLeftTwips.'" w:header="'.$ps->headerOffsetTwips.'" w:footer="'.$ps->footerOffsetTwips.'"/>'
            .'</w:sectPr>';

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
