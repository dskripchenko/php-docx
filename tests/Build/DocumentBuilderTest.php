<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Tests\Build;

use Dskripchenko\PhpDocx\Build\DocumentBuilder;
use Dskripchenko\PhpDocx\Build\ParagraphBuilder;
use Dskripchenko\PhpDocx\Document;
use Dskripchenko\PhpDocx\Element\HorizontalRule;
use Dskripchenko\PhpDocx\Element\PageBreak;
use Dskripchenko\PhpDocx\Element\Paragraph;
use Dskripchenko\PhpDocx\Element\Run;
use Dskripchenko\PhpDocx\Style\PageSetup;
use Dskripchenko\PhpDocx\Style\PaperSize;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DocumentBuilderTest extends TestCase
{
    #[Test]
    public function builds_empty_document(): void
    {
        $doc = DocumentBuilder::new()->build();
        self::assertInstanceOf(Document::class, $doc);
        self::assertSame([], $doc->section->body);
    }

    #[Test]
    public function paragraph_string_short_form(): void
    {
        $doc = DocumentBuilder::new()
            ->paragraph('Hello world')
            ->build();

        self::assertCount(1, $doc->section->body);
        /** @var Paragraph $p */
        $p = $doc->section->body[0];
        self::assertInstanceOf(Paragraph::class, $p);
        self::assertCount(1, $p->children);
        self::assertSame('Hello world', $p->children[0]->text);
    }

    #[Test]
    public function paragraph_closure_form_with_bold(): void
    {
        $doc = DocumentBuilder::new()
            ->paragraph(fn (ParagraphBuilder $p) => $p
                ->text('Hello ')
                ->bold('world')
                ->text('!')
            )
            ->build();

        /** @var Paragraph $p */
        $p = $doc->section->body[0];
        self::assertCount(3, $p->children);
        self::assertSame('Hello ', $p->children[0]->text);
        self::assertTrue($p->children[1]->style->bold);
        self::assertSame('world', $p->children[1]->text);
        self::assertSame('!', $p->children[2]->text);
    }

    #[Test]
    public function heading_sets_level(): void
    {
        $doc = DocumentBuilder::new()
            ->heading(2, 'Section title')
            ->build();
        /** @var Paragraph $p */
        $p = $doc->section->body[0];
        self::assertSame(2, $p->headingLevel);
        self::assertSame('Section title', $p->children[0]->text);
    }

    #[Test]
    public function invalid_heading_level_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DocumentBuilder::new()->heading(7, 'X');
    }

    #[Test]
    public function pageBreak_horizontalRule_emptyLine(): void
    {
        $doc = DocumentBuilder::new()
            ->paragraph('A')
            ->pageBreak()
            ->paragraph('B')
            ->horizontalRule()
            ->emptyLine()
            ->paragraph('C')
            ->build();
        $body = $doc->section->body;

        self::assertCount(6, $body);
        self::assertInstanceOf(Paragraph::class, $body[0]);
        self::assertInstanceOf(PageBreak::class, $body[1]);
        self::assertInstanceOf(Paragraph::class, $body[2]);
        self::assertInstanceOf(HorizontalRule::class, $body[3]);
        self::assertInstanceOf(Paragraph::class, $body[4]);
        self::assertSame([], $body[4]->children); // emptyLine
        self::assertInstanceOf(Paragraph::class, $body[5]);
    }

    #[Test]
    public function inline_styles_italic_underline_strike_sup_sub(): void
    {
        $doc = DocumentBuilder::new()
            ->paragraph(fn (ParagraphBuilder $p) => $p
                ->italic('it')
                ->underline('un')
                ->strike('st')
                ->sup('s')
                ->sub('b')
                ->lineBreak()
                ->text('end')
            )
            ->build();
        /** @var Paragraph $p */
        $p = $doc->section->body[0];

        self::assertTrue($p->children[0]->style->italic);
        self::assertTrue($p->children[1]->style->underline);
        self::assertTrue($p->children[2]->style->strikethrough);
        self::assertTrue($p->children[3]->style->superscript);
        self::assertTrue($p->children[4]->style->subscript);
        self::assertInstanceOf(\Dskripchenko\PhpDocx\Element\LineBreak::class, $p->children[5]);
        self::assertSame('end', $p->children[6]->text);
    }

    #[Test]
    public function pageSetup_applied_to_section(): void
    {
        $custom = new PageSetup(paperSize: PaperSize::A3);
        $doc = DocumentBuilder::new()
            ->pageSetup($custom)
            ->paragraph('x')
            ->build();
        self::assertSame(PaperSize::A3, $doc->section->pageSetup->paperSize);
    }

    #[Test]
    public function watermark_passes_through(): void
    {
        $doc = DocumentBuilder::new()
            ->watermark('DRAFT')
            ->paragraph('x')
            ->build();
        self::assertSame('DRAFT', $doc->watermarkText);
    }

    #[Test]
    public function block_method_appends_pre_built_paragraph(): void
    {
        $custom = new Paragraph([new Run('custom')]);
        $doc = DocumentBuilder::new()
            ->paragraph('A')
            ->block($custom)
            ->paragraph('C')
            ->build();
        self::assertCount(3, $doc->section->body);
        self::assertSame('custom', $doc->section->body[1]->children[0]->text);
    }

    #[Test]
    public function block_accepts_iterable(): void
    {
        $blocks = [new Paragraph([new Run('a')]), new Paragraph([new Run('b')])];
        $doc = DocumentBuilder::new()
            ->block($blocks)
            ->build();
        self::assertCount(2, $doc->section->body);
    }

    #[Test]
    public function toBytes_emits_valid_DOCX(): void
    {
        $bytes = DocumentBuilder::new()
            ->heading(1, 'Title')
            ->paragraph('Body')
            ->toBytes();
        // ZIP magic
        self::assertSame('PK', substr($bytes, 0, 2));
        self::assertGreaterThan(500, strlen($bytes));
    }

    #[Test]
    public function toFile_writes_file(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'docxbuilder-');
        $written = DocumentBuilder::new()->paragraph('x')->toFile($tmp);
        self::assertGreaterThan(0, $written);
        $contents = (string) file_get_contents($tmp);
        self::assertSame('PK', substr($contents, 0, 2));
        @unlink($tmp);
    }

    #[Test]
    public function paragraph_builder_callback_does_not_lose_heading_level(): void
    {
        // Callback мог бы сбросить headingLevel — проверим что heading() restore'ит.
        $doc = DocumentBuilder::new()
            ->heading(3, fn (ParagraphBuilder $p) => $p
                ->bold('Important')
                ->text(' note')
            )
            ->build();
        /** @var Paragraph $p */
        $p = $doc->section->body[0];
        self::assertSame(3, $p->headingLevel);
        self::assertCount(2, $p->children);
        self::assertTrue($p->children[0]->style->bold);
    }
}
