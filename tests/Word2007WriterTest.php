<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Tests;

use Dskripchenko\PhpDocx\Document;
use Dskripchenko\PhpDocx\Section;
use Dskripchenko\PhpDocx\Style\Orientation;
use Dskripchenko\PhpDocx\Style\PageSetup;
use Dskripchenko\PhpDocx\Style\PaperSize;
use Dskripchenko\PhpDocx\Element\Paragraph;
use Dskripchenko\PhpDocx\Element\Run;
use Dskripchenko\PhpDocx\Style\ParagraphStyle;
use Dskripchenko\PhpDocx\Writer\Word2007Writer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class Word2007WriterTest extends TestCase
{
    #[Test]
    public function it_writes_valid_zip_with_pk_signature(): void
    {
        $doc = new Document(new Section(body: []));
        $bytes = (new Word2007Writer)->write($doc);

        self::assertSame('PK', substr($bytes, 0, 2), 'DOCX-файл должен начинаться с PK (ZIP magic).');
        self::assertGreaterThan(500, strlen($bytes), 'DOCX-файл должен быть не пустым.');
    }

    #[Test]
    public function it_includes_required_ooxml_parts(): void
    {
        $doc = new Document(new Section(body: []));
        $bytes = (new Word2007Writer)->write($doc);

        $tmp = tempnam(sys_get_temp_dir(), 'docx-test-');
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

        self::assertContains('[Content_Types].xml', $names);
        self::assertContains('_rels/.rels', $names);
        self::assertContains('word/document.xml', $names);
        self::assertContains('word/_rels/document.xml.rels', $names);
    }

    #[Test]
    public function it_writes_a4_portrait_page_setup_by_default(): void
    {
        $doc = new Document(new Section(body: []));
        $bytes = (new Word2007Writer)->write($doc);

        $tmp = tempnam(sys_get_temp_dir(), 'docx-test-');
        file_put_contents($tmp, $bytes);
        try {
            $zip = new \ZipArchive;
            $zip->open($tmp);
            $xml = (string) $zip->getFromName('word/document.xml');
            $zip->close();
        } finally {
            @unlink($tmp);
        }

        // A4 portrait: w=11906, h=16839
        self::assertStringContainsString('w:w="11906"', $xml);
        self::assertStringContainsString('w:h="16839"', $xml);
        self::assertStringContainsString('w:orient="portrait"', $xml);
    }

    #[Test]
    public function it_writes_landscape_orientation(): void
    {
        $doc = new Document(new Section(
            body: [],
            pageSetup: new PageSetup(
                paperSize: PaperSize::A4,
                orientation: Orientation::Landscape,
            ),
        ));
        $bytes = (new Word2007Writer)->write($doc);

        $tmp = tempnam(sys_get_temp_dir(), 'docx-test-');
        file_put_contents($tmp, $bytes);
        try {
            $zip = new \ZipArchive;
            $zip->open($tmp);
            $xml = (string) $zip->getFromName('word/document.xml');
            $zip->close();
        } finally {
            @unlink($tmp);
        }

        // A4 landscape: w=16839 (swapped), orient=landscape
        self::assertStringContainsString('w:w="16839"', $xml);
        self::assertStringContainsString('w:orient="landscape"', $xml);
    }

    #[Test]
    public function it_writes_negative_first_line_indent_as_hanging(): void
    {
        // ST_TwipsMeasure is unsigned: a negative firstLine (our model of a
        // hanging indent) has to leave as the w:hanging attribute, otherwise
        // document.xml fails the ECMA-376 XSD (found by the corpus harness on
        // real Google Docs / Word documents).
        $doc = new Document(new Section(body: [
            new Paragraph([new Run('hang')], new ParagraphStyle(indentFirstLineTwips: -360)),
        ]));
        $bytes = (new Word2007Writer)->write($doc);

        $tmp = tempnam(sys_get_temp_dir(), 'docx-test-');
        file_put_contents($tmp, $bytes);
        try {
            $zip = new \ZipArchive;
            $zip->open($tmp);
            $xml = (string) $zip->getFromName('word/document.xml');
            $zip->close();
        } finally {
            @unlink($tmp);
        }

        self::assertStringContainsString('w:hanging="360"', $xml);
        self::assertStringNotContainsString('w:firstLine="-', $xml);
    }
}
