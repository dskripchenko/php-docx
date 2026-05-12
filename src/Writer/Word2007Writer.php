<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Writer;

use Dskripchenko\PhpDocx\Document;
use Dskripchenko\PhpDocx\Exception\DocxException;

/**
 * Writer Document → DOCX (Word2007 format). Phase 1: skeleton with minimal
 * valid DOCX structure (empty document).
 *
 * Phase 2+:
 *   - body/header/footer XML rendering
 *   - image embedding + relationships
 *   - styles.xml для Heading1..6
 *   - numbering.xml для списков
 *
 * Output: raw bytes DOCX-файла (готов к file_put_contents / HTTP response).
 */
final class Word2007Writer
{
    public function write(Document $document): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'docx-');
        if ($tmpFile === false) {
            throw new DocxException('Не удалось создать temp-файл для DOCX.');
        }

        $zip = new \ZipArchive;
        if ($zip->open($tmpFile, \ZipArchive::OVERWRITE) !== true) {
            @unlink($tmpFile);
            throw new DocxException('Не удалось открыть ZipArchive для записи.');
        }

        // Минимальные обязательные части OOXML-документа.
        $zip->addFromString('[Content_Types].xml', $this->renderContentTypes());
        $zip->addFromString('_rels/.rels', $this->renderRootRels());
        $zip->addFromString('word/document.xml', $this->renderDocumentXml($document));
        $zip->addFromString('word/_rels/document.xml.rels', $this->renderDocumentRels());

        $zip->close();

        $contents = (string) file_get_contents($tmpFile);
        @unlink($tmpFile);

        return $contents;
    }

    /**
     * `[Content_Types].xml` — MIME-карта частей пакета (OPC spec).
     */
    private function renderContentTypes(): string
    {
        return <<<XML
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
                <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
                <Default Extension="xml" ContentType="application/xml"/>
                <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
            </Types>
            XML;
    }

    /**
     * `_rels/.rels` — root-relationships, указывает на word/document.xml.
     */
    private function renderRootRels(): string
    {
        return <<<XML
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
                <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
            </Relationships>
            XML;
    }

    /**
     * `word/_rels/document.xml.rels` — relationships для document'а:
     * на header/footer/image. Phase 1: пустой.
     */
    private function renderDocumentRels(): string
    {
        return <<<XML
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>
            XML;
    }

    /**
     * `word/document.xml` — главный body документа. Phase 1: пустой <w:body>
     * с минимальным <w:sectPr> для valid-document.
     */
    private function renderDocumentXml(Document $document): string
    {
        $ps = $document->section->pageSetup;
        [$w, $h] = match ($ps->orientation) {
            \Dskripchenko\PhpDocx\Style\Orientation::Portrait =>
                [$ps->paperSize->widthTwips(), $ps->paperSize->heightTwips()],
            \Dskripchenko\PhpDocx\Style\Orientation::Landscape =>
                [$ps->paperSize->heightTwips(), $ps->paperSize->widthTwips()],
        };
        $orient = $ps->orientation->value;

        // TODO Phase 2: render body blocks via dedicated XML builder.
        return <<<XML
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <w:document
                xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"
                xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"
                xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"
            >
                <w:body>
                    <w:p/>
                    <w:sectPr>
                        <w:pgSz w:w="{$w}" w:h="{$h}" w:orient="{$orient}"/>
                        <w:pgMar w:top="{$ps->marginTopTwips}" w:right="{$ps->marginRightTwips}" w:bottom="{$ps->marginBottomTwips}" w:left="{$ps->marginLeftTwips}" w:header="{$ps->headerOffsetTwips}" w:footer="{$ps->footerOffsetTwips}"/>
                    </w:sectPr>
                </w:body>
            </w:document>
            XML;
    }
}
