<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Tests\Build;

use Dskripchenko\PhpDocx\Build\DocumentBuilder;
use Dskripchenko\PhpDocx\Build\ListBuilder;
use Dskripchenko\PhpDocx\Build\ParagraphBuilder;
use Dskripchenko\PhpDocx\Build\RunStyleBuilder;
use Dskripchenko\PhpDocx\Build\SectionContentBuilder;
use Dskripchenko\PhpDocx\Element\Bookmark;
use Dskripchenko\PhpDocx\Element\Field;
use Dskripchenko\PhpDocx\Element\Hyperlink;
use Dskripchenko\PhpDocx\Element\Image;
use Dskripchenko\PhpDocx\Element\ImageFormat;
use Dskripchenko\PhpDocx\Element\Paragraph;
use Dskripchenko\PhpDocx\Element\Run;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BuilderB5Test extends TestCase
{
    private const TINY_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    // ─────────── Header / Footer ─────────────────────────────────────────

    #[Test]
    public function header_and_footer_closures(): void
    {
        $doc = DocumentBuilder::new()
            ->header(fn (SectionContentBuilder $h) => $h->paragraph('Acme Inc.'))
            ->footer(fn (SectionContentBuilder $f) => $f
                ->paragraph(fn (ParagraphBuilder $p) => $p
                    ->text('Page ')
                    ->pageNumber()
                    ->text(' of ')
                    ->totalPages()
                )
            )
            ->paragraph('Body')
            ->build();

        self::assertNotEmpty($doc->section->header);
        self::assertNotEmpty($doc->section->footer);
        self::assertSame('Acme Inc.', $doc->section->header[0]->children[0]->text);

        // footer должен содержать PAGE и NUMPAGES field codes.
        /** @var Paragraph $fp */
        $fp = $doc->section->footer[0];
        $fields = array_values(array_filter($fp->children, fn ($c) => $c instanceof Field));
        self::assertCount(2, $fields);
        self::assertStringContainsString('PAGE', $fields[0]->instruction);
        self::assertStringContainsString('NUMPAGES', $fields[1]->instruction);
    }

    // ─────────── Fields ──────────────────────────────────────────────────

    #[Test]
    public function pageNumber_and_totalPages(): void
    {
        $doc = DocumentBuilder::new()
            ->paragraph(fn (ParagraphBuilder $p) => $p->pageNumber()->totalPages())
            ->build();
        $p = $doc->section->body[0];
        self::assertInstanceOf(Field::class, $p->children[0]);
        self::assertStringContainsString('PAGE', $p->children[0]->instruction);
        self::assertStringContainsString('NUMPAGES', $p->children[1]->instruction);
    }

    #[Test]
    public function currentDate_with_format(): void
    {
        $doc = DocumentBuilder::new()
            ->paragraph(fn (ParagraphBuilder $p) => $p->currentDate('yyyy-MM-dd'))
            ->build();
        /** @var Field $f */
        $f = $doc->section->body[0]->children[0];
        self::assertInstanceOf(Field::class, $f);
        self::assertStringContainsString('DATE', $f->instruction);
        self::assertStringContainsString('yyyy-MM-dd', $f->instruction);
    }

    #[Test]
    public function mergeField_emits_MERGEFIELD_instruction(): void
    {
        $doc = DocumentBuilder::new()
            ->paragraph(fn (ParagraphBuilder $p) => $p
                ->text('Hello ')
                ->mergeField('CustomerName')
            )
            ->build();
        /** @var Field $f */
        $f = $doc->section->body[0]->children[1];
        self::assertInstanceOf(Field::class, $f);
        self::assertStringContainsString('MERGEFIELD CustomerName', $f->instruction);
    }

    // ─────────── Hyperlinks ──────────────────────────────────────────────

    #[Test]
    public function external_link_short_form(): void
    {
        $doc = DocumentBuilder::new()
            ->paragraph(fn (ParagraphBuilder $p) => $p
                ->text('Visit ')
                ->link('https://example.com', 'website')
            )
            ->build();
        /** @var Hyperlink $h */
        $h = $doc->section->body[0]->children[1];
        self::assertInstanceOf(Hyperlink::class, $h);
        self::assertFalse($h->isInternal());
        self::assertSame('https://example.com', $h->href);
        self::assertSame('website', $h->children[0]->text);
    }

    #[Test]
    public function link_with_closure_content(): void
    {
        $doc = DocumentBuilder::new()
            ->paragraph(fn (ParagraphBuilder $p) => $p
                ->link('https://example.com', fn (ParagraphBuilder $i) => $i
                    ->bold('Important')
                    ->text(' link')
                )
            )
            ->build();
        /** @var Hyperlink $h */
        $h = $doc->section->body[0]->children[0];
        self::assertCount(2, $h->children);
        self::assertTrue($h->children[0]->style->bold);
    }

    #[Test]
    public function internal_link_yields_anchor(): void
    {
        $doc = DocumentBuilder::new()
            ->paragraph(fn (ParagraphBuilder $p) => $p
                ->internalLink('section1', 'go to section')
            )
            ->build();
        /** @var Hyperlink $h */
        $h = $doc->section->body[0]->children[0];
        self::assertTrue($h->isInternal());
        self::assertSame('section1', $h->anchor);
    }

    #[Test]
    public function bookmark_anchor(): void
    {
        $doc = DocumentBuilder::new()
            ->paragraph(fn (ParagraphBuilder $p) => $p
                ->bookmark('section1', 'Section Title')
            )
            ->build();
        /** @var Bookmark $b */
        $b = $doc->section->body[0]->children[0];
        self::assertInstanceOf(Bookmark::class, $b);
        self::assertSame('section1', $b->name);
        self::assertSame('Section Title', $b->children[0]->text);
    }

    #[Test]
    public function empty_bookmark_anchor(): void
    {
        $doc = DocumentBuilder::new()
            ->paragraph(fn (ParagraphBuilder $p) => $p->bookmark('mark1'))
            ->build();
        /** @var Bookmark $b */
        $b = $doc->section->body[0]->children[0];
        self::assertSame('mark1', $b->name);
        self::assertSame([], $b->children);
    }

    // ─────────── Images ──────────────────────────────────────────────────

    #[Test]
    public function inline_image_from_bytes(): void
    {
        $bytes = base64_decode(self::TINY_PNG_BASE64);
        $doc = DocumentBuilder::new()
            ->paragraph(fn (ParagraphBuilder $p) => $p
                ->text('see: ')
                ->imageFromBytes($bytes, ImageFormat::Png, 20, 20, 'logo')
            )
            ->build();
        /** @var Image $img */
        $img = $doc->section->body[0]->children[1];
        self::assertInstanceOf(Image::class, $img);
        self::assertSame(ImageFormat::Png, $img->format);
        self::assertSame('logo', $img->altText);
        self::assertSame(190500, $img->widthEmu); // 20*9525
    }

    #[Test]
    public function inline_image_from_data_url(): void
    {
        $doc = DocumentBuilder::new()
            ->paragraph(fn (ParagraphBuilder $p) => $p
                ->imageFromDataUrl('data:image/png;base64,'.self::TINY_PNG_BASE64)
            )
            ->build();
        /** @var Image $img */
        $img = $doc->section->body[0]->children[0];
        self::assertInstanceOf(Image::class, $img);
        self::assertSame(ImageFormat::Png, $img->format);
        // Auto-detect — 1x1 px from binary
        self::assertSame(9525, $img->widthEmu);
    }

    #[Test]
    public function block_image_from_bytes(): void
    {
        $bytes = base64_decode(self::TINY_PNG_BASE64);
        $doc = DocumentBuilder::new()
            ->paragraph('Before')
            ->imageFromBytes($bytes, ImageFormat::Png, 10, 10, 'pic')
            ->paragraph('After')
            ->build();

        self::assertCount(3, $doc->section->body);
        self::assertInstanceOf(Image::class, $doc->section->body[1]);
    }

    #[Test]
    public function image_from_file_with_autodetect(): void
    {
        $bytes = base64_decode(self::TINY_PNG_BASE64);
        $tmp = tempnam(sys_get_temp_dir(), 'imgtest-').'.png';
        file_put_contents($tmp, $bytes);
        try {
            $doc = DocumentBuilder::new()
                ->imageFromFile($tmp, altText: 'x')
                ->build();
            /** @var Image $img */
            $img = $doc->section->body[0];
            self::assertInstanceOf(Image::class, $img);
            self::assertSame(ImageFormat::Png, $img->format);
            self::assertSame('x', $img->altText);
        } finally {
            @unlink($tmp);
        }
    }

    #[Test]
    public function image_from_file_throws_when_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DocumentBuilder::new()->imageFromFile('/nonexistent.png');
    }

    // ─────────── Full kitchen-sink writeup ───────────────────────────────

    #[Test]
    public function kitchen_sink_emits_valid_docx(): void
    {
        // Все B1-B5 фичи в одном документе.
        $bytes = DocumentBuilder::new()
            ->watermark('DRAFT')
            ->header(fn ($h) => $h->paragraph('Acme Inc.'))
            ->footer(fn ($f) => $f->paragraph(fn ($p) => $p
                ->text('Page ')->pageNumber()->text(' of ')->totalPages()
            ))
            ->heading(1, 'Invoice #42')
            ->paragraph(fn ($p) => $p
                ->text('Generated on ')
                ->currentDate('dd MMMM yyyy')
                ->lineBreak()
                ->text('Customer: ')
                ->bold('Acme Co.')
                ->text(' | ID: ')
                ->mergeField('CustomerID')
            )
            ->paragraph(fn ($p) => $p
                ->bookmark('terms', 'Terms')
                ->text(': see ')
                ->internalLink('appendix', 'appendix')
                ->text(' or ')
                ->link('https://example.com', 'website')
            )
            ->table(fn ($t) => $t
                ->headerRow(['Item', 'Qty', 'Price'])
                ->row(['Widget', '2', '20$'])
            )
            ->bulletList(fn (ListBuilder $l) => $l
                ->item('Important')
                ->item(fn ($i) => $i->styled('Critical', fn (RunStyleBuilder $s) => $s->color('ff0000')))
            )
            ->paragraph(fn ($p) => $p->bookmark('appendix', 'Appendix'))
            ->paragraph('Appendix content here.')
            ->toBytes();

        self::assertSame('PK', substr($bytes, 0, 2));
        self::assertGreaterThan(1000, strlen($bytes));
    }

    /**
     * Bookmark на document-level (без paragraph closure).
     */
    #[Test]
    public function bookmark_at_document_level_via_paragraph(): void
    {
        // Bookmark — inline; на document-level — кладём в одно-runовый параграф.
        $doc = DocumentBuilder::new()
            ->paragraph(fn (ParagraphBuilder $p) => $p->bookmark('anchor1', 'X'))
            ->build();
        $b = $doc->section->body[0]->children[0];
        self::assertInstanceOf(Bookmark::class, $b);
    }
}
