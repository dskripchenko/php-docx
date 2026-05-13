<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Tests\Reader;

use Dskripchenko\PhpDocx\Element\LineBreak;
use Dskripchenko\PhpDocx\Element\PageBreak;
use Dskripchenko\PhpDocx\Element\Paragraph;
use Dskripchenko\PhpDocx\Element\Run;
use Dskripchenko\PhpDocx\Html\Converter;
use Dskripchenko\PhpDocx\Reader\BodyReader;
use Dskripchenko\PhpDocx\Reader\DocxPackageReader;
use Dskripchenko\PhpDocx\Reader\OoxmlNs;
use Dskripchenko\PhpDocx\Reader\StylesResolver;
use Dskripchenko\PhpDocx\Style\Alignment;
use Dskripchenko\PhpDocx\Writer\Word2007Writer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BodyReaderTest extends TestCase
{
    #[Test]
    public function single_paragraph_with_text(): void
    {
        $body = $this->loadBody('<w:p><w:r><w:t>Hello world</w:t></w:r></w:p>');
        $blocks = (new BodyReader)->read($body);

        self::assertCount(1, $blocks);
        $p = $blocks[0];
        self::assertInstanceOf(Paragraph::class, $p);
        self::assertCount(1, $p->children);
        /** @var Run $run */
        $run = $p->children[0];
        self::assertSame('Hello world', $run->text);
    }

    #[Test]
    public function multiple_runs_in_paragraph(): void
    {
        $body = $this->loadBody(
            '<w:p>'
            .'<w:r><w:t>A</w:t></w:r>'
            .'<w:r><w:rPr><w:b/></w:rPr><w:t>B</w:t></w:r>'
            .'<w:r><w:t>C</w:t></w:r>'
            .'</w:p>'
        );
        $blocks = (new BodyReader)->read($body);
        /** @var Paragraph $p */
        $p = $blocks[0];
        self::assertCount(3, $p->children);
        self::assertSame('A', $p->children[0]->text);
        self::assertTrue($p->children[1]->style->bold);
        self::assertSame('B', $p->children[1]->text);
        self::assertFalse($p->children[2]->style->bold);
    }

    #[Test]
    public function heading_level_from_pStyle(): void
    {
        $body = $this->loadBody(
            '<w:p><w:pPr><w:pStyle w:val="Heading2"/></w:pPr>'
            .'<w:r><w:t>Title</w:t></w:r></w:p>'
        );
        $blocks = (new BodyReader)->read($body);
        /** @var Paragraph $p */
        $p = $blocks[0];
        self::assertSame(2, $p->headingLevel);
    }

    #[Test]
    public function line_break_within_run(): void
    {
        $body = $this->loadBody(
            '<w:p><w:r><w:t>A</w:t><w:br/><w:t>B</w:t></w:r></w:p>'
        );
        $blocks = (new BodyReader)->read($body);
        /** @var Paragraph $p */
        $p = $blocks[0];
        // A → linebreak → B → 3 inlines
        self::assertCount(3, $p->children);
        self::assertSame('A', $p->children[0]->text);
        self::assertInstanceOf(LineBreak::class, $p->children[1]);
        self::assertSame('B', $p->children[2]->text);
    }

    #[Test]
    public function page_break_splits_paragraph(): void
    {
        $body = $this->loadBody(
            '<w:p><w:r><w:t>Before</w:t></w:r>'
            .'<w:r><w:br w:type="page"/></w:r>'
            .'<w:r><w:t>After</w:t></w:r></w:p>'
        );
        $blocks = (new BodyReader)->read($body);
        // 3 blocks: paragraph(Before) → PageBreak → paragraph(After)
        self::assertCount(3, $blocks);
        self::assertInstanceOf(Paragraph::class, $blocks[0]);
        self::assertInstanceOf(PageBreak::class, $blocks[1]);
        self::assertInstanceOf(Paragraph::class, $blocks[2]);
        self::assertSame('Before', $blocks[0]->children[0]->text);
        self::assertSame('After', $blocks[2]->children[0]->text);
    }

    #[Test]
    public function alignment_from_pPr(): void
    {
        $body = $this->loadBody(
            '<w:p><w:pPr><w:jc w:val="center"/></w:pPr>'
            .'<w:r><w:t>X</w:t></w:r></w:p>'
        );
        $blocks = (new BodyReader)->read($body);
        /** @var Paragraph $p */
        $p = $blocks[0];
        self::assertSame(Alignment::Center, $p->style->alignment);
    }

    #[Test]
    public function tab_becomes_text_tab(): void
    {
        $body = $this->loadBody(
            '<w:p><w:r><w:t>A</w:t><w:tab/><w:t>B</w:t></w:r></w:p>'
        );
        $blocks = (new BodyReader)->read($body);
        /** @var Paragraph $p */
        $p = $blocks[0];
        self::assertCount(1, $p->children);
        self::assertSame("A\tB", $p->children[0]->text);
    }

    #[Test]
    public function empty_paragraph_yields_one_empty(): void
    {
        $body = $this->loadBody('<w:p/>');
        $blocks = (new BodyReader)->read($body);
        self::assertCount(1, $blocks);
        /** @var Paragraph $p */
        $p = $blocks[0];
        self::assertSame([], $p->children);
    }

    #[Test]
    public function sectPr_is_skipped(): void
    {
        $body = $this->loadBody(
            '<w:p><w:r><w:t>X</w:t></w:r></w:p>'
            .'<w:sectPr><w:pgSz w:w="11906" w:h="16838"/></w:sectPr>'
        );
        $blocks = (new BodyReader)->read($body);
        self::assertCount(1, $blocks);
    }

    #[Test]
    public function unknown_block_elements_skipped_silently(): void
    {
        $body = $this->loadBody('<w:p><w:r><w:t>x</w:t></w:r></w:p><w:unknownBlock/>');
        $blocks = (new BodyReader)->read($body);
        self::assertCount(1, $blocks);
    }

    #[Test]
    public function roundtrip_writer_then_reader_preserves_basic_text(): void
    {
        $bytes = (new Word2007Writer)->write((new Converter)->fromHtml(
            '<h1>Title</h1>'
            .'<p>Plain paragraph with <strong>bold</strong> and <em>italic</em>.</p>'
            .'<p>Second paragraph.</p>'
        ));

        $pkg = (new DocxPackageReader)->read($bytes);
        $resolver = new StylesResolver($pkg->stylesXml);
        $reader = new BodyReader($resolver);
        $body = $pkg->documentXml->getElementsByTagNameNS(OoxmlNs::W, 'body')->item(0);
        self::assertInstanceOf(\DOMElement::class, $body);
        $blocks = $reader->read($body);

        // Должно быть 3 параграфа (h1 + 2 p).
        $paragraphs = array_values(array_filter($blocks, fn ($b) => $b instanceof Paragraph));
        self::assertCount(3, $paragraphs);

        self::assertSame(1, $paragraphs[0]->headingLevel);
        self::assertNull($paragraphs[1]->headingLevel);

        // Текст «Title» в первом.
        self::assertStringContainsString('Title', $this->extractText($paragraphs[0]));
        // bold mark внутри второго.
        $secondText = $this->extractText($paragraphs[1]);
        self::assertStringContainsString('bold', $secondText);
        $hasBold = false;
        $hasItalic = false;
        foreach ($paragraphs[1]->children as $r) {
            if ($r instanceof Run) {
                if ($r->style->bold) {
                    $hasBold = true;
                }
                if ($r->style->italic) {
                    $hasItalic = true;
                }
            }
        }
        self::assertTrue($hasBold);
        self::assertTrue($hasItalic);
    }

    #[Test]
    public function w_t_preserve_whitespace(): void
    {
        $body = $this->loadBody(
            '<w:p><w:r><w:t xml:space="preserve">  hello  </w:t></w:r></w:p>'
        );
        $blocks = (new BodyReader)->read($body);
        /** @var Paragraph $p */
        $p = $blocks[0];
        self::assertSame('  hello  ', $p->children[0]->text);
    }

    private function loadBody(string $bodyInner): \DOMElement
    {
        $doc = new \DOMDocument;
        $doc->loadXML(
            '<w:document xmlns:w="'.OoxmlNs::W.'"><w:body>'.$bodyInner.'</w:body></w:document>'
        );
        $body = $doc->getElementsByTagNameNS(OoxmlNs::W, 'body')->item(0);
        if (! $body instanceof \DOMElement) {
            throw new \RuntimeException('No body in fixture');
        }

        return $body;
    }

    private function extractText(Paragraph $p): string
    {
        $text = '';
        foreach ($p->children as $c) {
            if ($c instanceof Run) {
                $text .= $c->text;
            }
        }

        return $text;
    }
}
