<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Tests\Reader;

use Dskripchenko\PhpDocx\Document;
use Dskripchenko\PhpDocx\Element\Paragraph;
use Dskripchenko\PhpDocx\Element\Run;
use Dskripchenko\PhpDocx\Html\Converter;
use Dskripchenko\PhpDocx\Reader\DocxReader;
use Dskripchenko\PhpDocx\Style\Orientation;
use Dskripchenko\PhpDocx\Style\PaperSize;
use Dskripchenko\PhpDocx\Writer\Word2007Writer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DocxReaderTest extends TestCase
{
    #[Test]
    public function reads_simple_document(): void
    {
        $bytes = $this->writeDocx(body: '<p>Hello world</p>');
        $doc = (new DocxReader)->read($bytes);

        self::assertInstanceOf(Document::class, $doc);
        self::assertNotEmpty($doc->section->body);
        /** @var Paragraph $p */
        $p = $doc->section->body[0];
        self::assertSame('Hello world', $this->extractText($p));
    }

    #[Test]
    public function reads_header_and_footer(): void
    {
        $bytes = (new Word2007Writer)->write((new Converter)->fromHtml(
            body: '<p>Body</p>',
            header: '<p>Header</p>',
            footer: '<p>Footer</p>',
        ));
        $doc = (new DocxReader)->read($bytes);

        self::assertNotEmpty($doc->section->header);
        self::assertNotEmpty($doc->section->footer);
        self::assertSame('Header', $this->extractText($doc->section->header[0]));
        self::assertSame('Footer', $this->extractText($doc->section->footer[0]));
    }

    #[Test]
    public function reads_watermark_text(): void
    {
        $bytes = (new Word2007Writer)->write((new Converter)->fromHtml(
            body: '<p>x</p>',
            watermarkText: 'CONFIDENTIAL',
        ));
        $doc = (new DocxReader)->read($bytes);

        self::assertSame('CONFIDENTIAL', $doc->watermarkText);
    }

    #[Test]
    public function watermark_removed_from_header_blocks(): void
    {
        $bytes = (new Word2007Writer)->write((new Converter)->fromHtml(
            body: '<p>x</p>',
            header: '<p>Real header</p>',
            watermarkText: 'DRAFT',
        ));
        $doc = (new DocxReader)->read($bytes);

        self::assertSame('DRAFT', $doc->watermarkText);
        // header.blocks не должны содержать "DRAFT" текст.
        $headerText = '';
        foreach ($doc->section->header as $b) {
            if ($b instanceof Paragraph) {
                $headerText .= $this->extractText($b);
            }
        }
        self::assertStringNotContainsString('DRAFT', $headerText);
        self::assertStringContainsString('Real header', $headerText);
    }

    #[Test]
    public function reads_page_setup_default_A4_portrait(): void
    {
        $bytes = $this->writeDocx(body: '<p>x</p>');
        $doc = (new DocxReader)->read($bytes);

        self::assertSame(PaperSize::A4, $doc->section->pageSetup->paperSize);
        self::assertSame(Orientation::Portrait, $doc->section->pageSetup->orientation);
    }

    #[Test]
    public function full_document_round_trip(): void
    {
        $original = '<h1>Title</h1>'
            .'<p>Paragraph with <strong>bold</strong> and <em>italic</em>.</p>'
            .'<table><tr><th>A</th><th>B</th></tr><tr><td>1</td><td>2</td></tr></table>'
            .'<ul><li>x</li><li>y</li></ul>'
            .'<p><a href="https://example.com">link</a></p>';

        $bytes = $this->writeDocx(body: $original);
        $doc = (new DocxReader)->read($bytes);

        // Должны быть Heading1 paragraph + paragraph + table + list + paragraph.
        $body = $doc->section->body;
        self::assertGreaterThanOrEqual(4, count($body));

        // Find heading
        $hasHeading = false;
        foreach ($body as $b) {
            if ($b instanceof Paragraph && $b->headingLevel === 1) {
                $hasHeading = true;
                break;
            }
        }
        self::assertTrue($hasHeading);
    }

    #[Test]
    public function reader_can_be_fed_back_into_writer(): void
    {
        // Round-trip: bytes → AST → bytes — должно работать без exception.
        $bytes = $this->writeDocx(body: '<h1>X</h1><p>y</p>');
        $doc = (new DocxReader)->read($bytes);
        $re = (new Word2007Writer)->write($doc);

        // Valid DOCX (PK ZIP magic)
        self::assertSame('PK', substr($re, 0, 2));
    }

    private function writeDocx(string $body): string
    {
        return (new Word2007Writer)->write((new Converter)->fromHtml($body));
    }

    private function extractText(\Dskripchenko\PhpDocx\Element\BlockElement $b): string
    {
        if (! $b instanceof Paragraph) {
            return '';
        }
        $text = '';
        foreach ($b->children as $c) {
            if ($c instanceof Run) {
                $text .= $c->text;
            }
        }

        return $text;
    }
}
