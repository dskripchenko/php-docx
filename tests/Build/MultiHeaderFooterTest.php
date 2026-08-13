<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Tests\Build;

use Dskripchenko\PhpDocx\Build\DocumentBuilder;
use Dskripchenko\PhpDocx\Build\ParagraphBuilder;
use Dskripchenko\PhpDocx\Build\SectionContentBuilder;
use Dskripchenko\PhpDocx\Element\Paragraph;
use Dskripchenko\PhpDocx\Element\Run;
use Dskripchenko\PhpDocx\Reader\DocxPackageReader;
use Dskripchenko\PhpDocx\Reader\DocxReader;
use Dskripchenko\PhpDocx\Section;
use Dskripchenko\PhpDocx\Writer\Word2007Writer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MultiHeaderFooterTest extends TestCase
{
    #[Test]
    public function default_only_header_footer_remains_default(): void
    {
        $doc = DocumentBuilder::new()
            ->header(fn (SectionContentBuilder $h) => $h->paragraph('Default header'))
            ->footer(fn (SectionContentBuilder $f) => $f->paragraph('Default footer'))
            ->paragraph('Body')
            ->build();

        self::assertCount(1, $doc->section->allHeaders());
        self::assertCount(1, $doc->section->allFooters());
        self::assertArrayHasKey('default', $doc->section->allHeaders());
        self::assertSame([], $doc->section->firstHeader);
        self::assertSame([], $doc->section->evenHeader);
    }

    #[Test]
    public function first_header_and_default_coexist(): void
    {
        $doc = DocumentBuilder::new()
            ->header(fn ($h) => $h->paragraph('Page 2+'))
            ->firstHeader(fn ($h) => $h->paragraph('Title page'))
            ->paragraph('Body')
            ->build();

        self::assertCount(2, $doc->section->allHeaders());
        self::assertTrue($doc->section->hasFirstPageHeaderOrFooter());

        /** @var Paragraph $first */
        $first = $doc->section->firstHeader[0];
        self::assertSame('Title page', $first->children[0]->text);
        /** @var Paragraph $def */
        $def = $doc->section->header[0];
        self::assertSame('Page 2+', $def->children[0]->text);
    }

    #[Test]
    public function even_header_and_footer(): void
    {
        $doc = DocumentBuilder::new()
            ->header(fn ($h) => $h->paragraph('odd hdr'))
            ->evenHeader(fn ($h) => $h->paragraph('even hdr'))
            ->footer(fn ($f) => $f->paragraph('odd ftr'))
            ->evenFooter(fn ($f) => $f->paragraph('even ftr'))
            ->paragraph('Body')
            ->build();

        self::assertCount(2, $doc->section->allHeaders());
        self::assertCount(2, $doc->section->allFooters());
        self::assertTrue($doc->section->hasEvenPageHeaderOrFooter());
    }

    #[Test]
    public function writer_emits_titlePg_when_first_header_set(): void
    {
        $bytes = DocumentBuilder::new()
            ->firstHeader(fn ($h) => $h->paragraph('first'))
            ->paragraph('body')
            ->toBytes();

        $pkg = (new DocxPackageReader)->read($bytes);
        $docXml = $pkg->documentXml->saveXML();
        self::assertNotFalse($docXml);
        self::assertStringContainsString('<w:titlePg/>', $docXml);
    }

    #[Test]
    public function writer_emits_evenAndOddHeaders_in_settings_when_even_set(): void
    {
        $bytes = DocumentBuilder::new()
            ->evenHeader(fn ($h) => $h->paragraph('even'))
            ->paragraph('body')
            ->toBytes();

        $pkg = (new DocxPackageReader)->read($bytes);
        self::assertNotNull($pkg->settingsXml);
        $settingsStr = $pkg->settingsXml->saveXML();
        self::assertNotFalse($settingsStr);
        self::assertStringContainsString('<w:evenAndOddHeaders/>', $settingsStr);
    }

    #[Test]
    public function no_settings_xml_when_no_even_headers(): void
    {
        $bytes = DocumentBuilder::new()
            ->header(fn ($h) => $h->paragraph('h'))
            ->paragraph('body')
            ->toBytes();

        $pkg = (new DocxPackageReader)->read($bytes);
        self::assertNull($pkg->settingsXml);
    }

    #[Test]
    public function writer_emits_three_header_parts_default_first_even(): void
    {
        $bytes = DocumentBuilder::new()
            ->header(fn ($h) => $h->paragraph('default'))
            ->firstHeader(fn ($h) => $h->paragraph('first'))
            ->evenHeader(fn ($h) => $h->paragraph('even'))
            ->paragraph('body')
            ->toBytes();

        $pkg = (new DocxPackageReader)->read($bytes);
        self::assertCount(3, $pkg->headers);

        // sectPr has to hold 3 <w:headerReference> entries.
        $xml = $pkg->documentXml->saveXML();
        self::assertNotFalse($xml);
        self::assertStringContainsString('w:type="default"', $xml);
        self::assertStringContainsString('w:type="first"', $xml);
        self::assertStringContainsString('w:type="even"', $xml);
    }

    #[Test]
    public function reader_resolves_each_header_type_from_sectPr(): void
    {
        // Bytes round-trip: write 3 types, read back, ensure correct mapping.
        $bytes = DocumentBuilder::new()
            ->header(fn ($h) => $h->paragraph('DEF'))
            ->firstHeader(fn ($h) => $h->paragraph('FRST'))
            ->evenHeader(fn ($h) => $h->paragraph('EVN'))
            ->paragraph('Body')
            ->toBytes();

        $doc = (new DocxReader)->read($bytes);
        self::assertSame('DEF', $this->extractText($doc->section->header));
        self::assertSame('FRST', $this->extractText($doc->section->firstHeader));
        self::assertSame('EVN', $this->extractText($doc->section->evenHeader));
    }

    #[Test]
    public function reader_resolves_footer_types(): void
    {
        $bytes = DocumentBuilder::new()
            ->footer(fn ($f) => $f->paragraph('DEF'))
            ->firstFooter(fn ($f) => $f->paragraph('FRST'))
            ->evenFooter(fn ($f) => $f->paragraph('EVN'))
            ->paragraph('Body')
            ->toBytes();

        $doc = (new DocxReader)->read($bytes);
        self::assertSame('DEF', $this->extractText($doc->section->footer));
        self::assertSame('FRST', $this->extractText($doc->section->firstFooter));
        self::assertSame('EVN', $this->extractText($doc->section->evenFooter));
    }

    #[Test]
    public function watermark_with_first_header_works(): void
    {
        // The watermark goes into the default header; the first-page one is separate.
        $bytes = DocumentBuilder::new()
            ->watermark('DRAFT')
            ->firstHeader(fn ($h) => $h->paragraph('Title page'))
            ->paragraph('Body')
            ->toBytes();

        $doc = (new DocxReader)->read($bytes);
        self::assertSame('DRAFT', $doc->watermarkText);
        self::assertSame('Title page', $this->extractText($doc->section->firstHeader));
    }

    #[Test]
    public function only_first_header_no_default_still_emits_titlePg(): void
    {
        $bytes = DocumentBuilder::new()
            ->firstHeader(fn ($h) => $h->paragraph('cover'))
            ->paragraph('body')
            ->toBytes();

        $pkg = (new DocxPackageReader)->read($bytes);
        $xml = $pkg->documentXml->saveXML();
        self::assertNotFalse($xml);
        self::assertStringContainsString('<w:titlePg/>', $xml);
        self::assertStringContainsString('w:type="first"', $xml);
    }

    #[Test]
    public function paragraph_builder_inside_first_header(): void
    {
        $doc = DocumentBuilder::new()
            ->firstHeader(fn (SectionContentBuilder $h) => $h
                ->paragraph(fn (ParagraphBuilder $p) => $p
                    ->bold('Confidential')
                    ->text(' document')
                )
            )
            ->paragraph('body')
            ->build();

        $p = $doc->section->firstHeader[0];
        self::assertInstanceOf(Paragraph::class, $p);
        self::assertTrue($p->children[0]->style->bold);
    }

    #[Test]
    public function bytes_round_trip_preserves_all_header_types(): void
    {
        $bytes1 = DocumentBuilder::new()
            ->header(fn ($h) => $h->paragraph('D'))
            ->firstHeader(fn ($h) => $h->paragraph('F'))
            ->evenHeader(fn ($h) => $h->paragraph('E'))
            ->paragraph('body')
            ->toBytes();

        $doc = (new DocxReader)->read($bytes1);
        // Re-emit
        $bytes2 = (new Word2007Writer)->write($doc);

        // Re-read
        $doc2 = (new DocxReader)->read($bytes2);
        self::assertSame('D', $this->extractText($doc2->section->header));
        self::assertSame('F', $this->extractText($doc2->section->firstHeader));
        self::assertSame('E', $this->extractText($doc2->section->evenHeader));
    }

    /**
     * @param  list<\Dskripchenko\PhpDocx\Element\BlockElement>  $blocks
     */
    private function extractText(array $blocks): string
    {
        $text = '';
        foreach ($blocks as $b) {
            if (! $b instanceof Paragraph) {
                continue;
            }
            foreach ($b->children as $c) {
                if ($c instanceof Run) {
                    $text .= $c->text;
                }
            }
        }

        return $text;
    }
}
