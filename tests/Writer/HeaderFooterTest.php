<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Tests\Writer;

use Dskripchenko\PhpDocx\Document;
use Dskripchenko\PhpDocx\Element\Paragraph;
use Dskripchenko\PhpDocx\Element\Run;
use Dskripchenko\PhpDocx\Section;
use Dskripchenko\PhpDocx\Writer\Word2007Writer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HeaderFooterTest extends TestCase
{
    #[Test]
    public function it_writes_header_xml_when_section_has_header(): void
    {
        $doc = new Document(new Section(
            body: [new Paragraph([new Run('body')])],
            header: [new Paragraph([new Run('header text')])],
        ));
        $bytes = (new Word2007Writer)->write($doc);
        $names = self::zipEntries($bytes);

        self::assertContains('word/header1.xml', $names);

        $headerXml = self::extract($bytes, 'word/header1.xml');
        self::assertStringContainsString('<w:hdr', $headerXml);
        self::assertStringContainsString('header text', $headerXml);
    }

    #[Test]
    public function it_writes_footer_xml_when_section_has_footer(): void
    {
        $doc = new Document(new Section(
            body: [new Paragraph([new Run('body')])],
            footer: [new Paragraph([new Run('footer text')])],
        ));
        $bytes = (new Word2007Writer)->write($doc);
        $names = self::zipEntries($bytes);

        self::assertContains('word/footer1.xml', $names);

        $footerXml = self::extract($bytes, 'word/footer1.xml');
        self::assertStringContainsString('<w:ftr', $footerXml);
        self::assertStringContainsString('footer text', $footerXml);
    }

    #[Test]
    public function it_registers_header_footer_relationship_and_sectpr_reference(): void
    {
        $doc = new Document(new Section(
            body: [new Paragraph([new Run('body')])],
            header: [new Paragraph([new Run('h')])],
            footer: [new Paragraph([new Run('f')])],
        ));
        $bytes = (new Word2007Writer)->write($doc);

        $docXml = self::extract($bytes, 'word/document.xml');
        $rels = self::extract($bytes, 'word/_rels/document.xml.rels');
        $contentTypes = self::extract($bytes, '[Content_Types].xml');

        self::assertStringContainsString('<w:headerReference w:type="default"', $docXml);
        self::assertStringContainsString('<w:footerReference w:type="default"', $docXml);
        self::assertStringContainsString('Target="header1.xml"', $rels);
        self::assertStringContainsString('Target="footer1.xml"', $rels);
        self::assertStringContainsString('header+xml', $contentTypes);
        self::assertStringContainsString('footer+xml', $contentTypes);
    }

    #[Test]
    public function it_writes_watermark_as_vml_shape(): void
    {
        $doc = new Document(
            section: new Section(body: [new Paragraph([new Run('body')])]),
            watermarkText: 'ОБРАЗЕЦ',
        );
        $bytes = (new Word2007Writer)->write($doc);

        $headerXml = self::extract($bytes, 'word/header1.xml');
        // VML shape с textpath, rotation, transparency, абсолютным позиционированием.
        self::assertStringContainsString('<v:shapetype id="_x0000_t136"', $headerXml);
        self::assertStringContainsString('type="#_x0000_t136"', $headerXml);
        self::assertStringContainsString('rotation:315', $headerXml);
        self::assertStringContainsString('opacity=".5"', $headerXml);
        self::assertStringContainsString('string="ОБРАЗЕЦ"', $headerXml);
        // VML namespaces в <w:hdr>
        self::assertStringContainsString('xmlns:v="urn:schemas-microsoft-com:vml"', $headerXml);
        self::assertStringContainsString('xmlns:o="urn:schemas-microsoft-com:office:office"', $headerXml);
    }

    #[Test]
    public function watermark_does_not_create_header_when_text_is_null(): void
    {
        $doc = new Document(
            section: new Section(body: [new Paragraph([new Run('body')])]),
            watermarkText: null,
        );
        $bytes = (new Word2007Writer)->write($doc);
        $names = self::zipEntries($bytes);

        self::assertNotContains('word/header1.xml', $names);
    }

    #[Test]
    public function watermark_prepends_to_existing_header(): void
    {
        $doc = new Document(
            section: new Section(
                body: [new Paragraph([new Run('body')])],
                header: [new Paragraph([new Run('real header')])],
            ),
            watermarkText: 'DRAFT',
        );
        $bytes = (new Word2007Writer)->write($doc);
        $headerXml = self::extract($bytes, 'word/header1.xml');

        // Оба должны присутствовать; watermark идёт первым
        self::assertStringContainsString('DRAFT', $headerXml);
        self::assertStringContainsString('real header', $headerXml);
        $watermarkPos = strpos($headerXml, 'DRAFT');
        $realPos = strpos($headerXml, 'real header');
        self::assertNotFalse($watermarkPos);
        self::assertNotFalse($realPos);
        self::assertLessThan($realPos, $watermarkPos);
    }

    /**
     * @return list<string>
     */
    private static function zipEntries(string $bytes): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'docx-hf-');
        file_put_contents($tmp, $bytes);
        try {
            $zip = new \ZipArchive;
            $zip->open($tmp);
            $names = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $names[] = $zip->getNameIndex($i);
            }
            $zip->close();
        } finally {
            @unlink($tmp);
        }

        return $names;
    }

    private static function extract(string $bytes, string $path): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'docx-hf-');
        file_put_contents($tmp, $bytes);
        try {
            $zip = new \ZipArchive;
            $zip->open($tmp);
            $content = (string) $zip->getFromName($path);
            $zip->close();
        } finally {
            @unlink($tmp);
        }

        return $content;
    }
}
