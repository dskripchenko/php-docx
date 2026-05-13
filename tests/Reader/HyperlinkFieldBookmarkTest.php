<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Tests\Reader;

use Dskripchenko\PhpDocx\Element\Bookmark;
use Dskripchenko\PhpDocx\Element\Field;
use Dskripchenko\PhpDocx\Element\Hyperlink;
use Dskripchenko\PhpDocx\Element\Paragraph;
use Dskripchenko\PhpDocx\Element\Run;
use Dskripchenko\PhpDocx\Html\Converter;
use Dskripchenko\PhpDocx\Reader\BodyReader;
use Dskripchenko\PhpDocx\Reader\DocxPackageReader;
use Dskripchenko\PhpDocx\Reader\NumberingReader;
use Dskripchenko\PhpDocx\Reader\OoxmlNs;
use Dskripchenko\PhpDocx\Reader\StylesResolver;
use Dskripchenko\PhpDocx\Writer\Word2007Writer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HyperlinkFieldBookmarkTest extends TestCase
{
    #[Test]
    public function external_hyperlink_resolves_via_rels(): void
    {
        $bytes = $this->writeDocx('<p><a href="https://example.com">link</a></p>');
        $blocks = $this->readBlocks($bytes);

        $links = $this->collectByType($blocks, Hyperlink::class);
        self::assertCount(1, $links);
        self::assertSame('https://example.com', $links[0]->href);
        self::assertFalse($links[0]->isInternal());
    }

    #[Test]
    public function internal_hyperlink_anchor(): void
    {
        $bytes = $this->writeDocx('<p><a href="#section1">go</a></p>');
        $blocks = $this->readBlocks($bytes);

        $links = $this->collectByType($blocks, Hyperlink::class);
        self::assertCount(1, $links);
        self::assertTrue($links[0]->isInternal());
        self::assertSame('section1', $links[0]->anchor);
    }

    #[Test]
    public function bookmark_wraps_inline_content(): void
    {
        $bytes = $this->writeDocx('<p><a id="anchor1">Heading text</a></p>');
        $blocks = $this->readBlocks($bytes);

        $bookmarks = $this->collectByType($blocks, Bookmark::class);
        self::assertCount(1, $bookmarks);
        self::assertSame('anchor1', $bookmarks[0]->name);
        // bookmark должен содержать text "Heading text"
        $text = '';
        foreach ($bookmarks[0]->children as $c) {
            if ($c instanceof Run) {
                $text .= $c->text;
            }
        }
        self::assertSame('Heading text', $text);
    }

    #[Test]
    public function bookmark_followed_by_internal_link_target(): void
    {
        $bytes = $this->writeDocx(
            '<p><a id="top">Top</a></p>'
            .'<p><a href="#top">back</a></p>'
        );
        $blocks = $this->readBlocks($bytes);

        $bookmarks = $this->collectByType($blocks, Bookmark::class);
        $links = $this->collectByType($blocks, Hyperlink::class);
        self::assertCount(1, $bookmarks);
        self::assertCount(1, $links);
        self::assertSame('top', $bookmarks[0]->name);
        self::assertSame('top', $links[0]->anchor);
    }

    #[Test]
    public function fldSimple_page_field(): void
    {
        $bytes = $this->writeDocx(
            '<p>page <page-number/> of <page-total/></p>'
        );
        $blocks = $this->readBlocks($bytes);

        $fields = $this->collectByType($blocks, Field::class);
        self::assertCount(2, $fields);
        self::assertStringContainsString('PAGE', $fields[0]->instruction);
        self::assertStringContainsString('NUMPAGES', $fields[1]->instruction);
    }

    #[Test]
    public function fldSimple_date_field_preserves_format(): void
    {
        $bytes = $this->writeDocx('<p>Date: <current-date format="yyyy-MM-dd"/></p>');
        $blocks = $this->readBlocks($bytes);

        $fields = $this->collectByType($blocks, Field::class);
        self::assertCount(1, $fields);
        self::assertStringContainsString('DATE', $fields[0]->instruction);
        self::assertStringContainsString('yyyy-MM-dd', $fields[0]->instruction);
    }

    #[Test]
    public function complex_fldChar_field_emitted_as_field(): void
    {
        // Симулируем complex field вручную — Writer наш всегда эмитит
        // fldSimple, но другие редакторы (Word) могут эмитить complex.
        $bodyXml =
            '<w:p>'
            .'<w:r><w:fldChar w:fldCharType="begin"/></w:r>'
            .'<w:r><w:instrText>MERGEFIELD CustomerName \\* MERGEFORMAT</w:instrText></w:r>'
            .'<w:r><w:fldChar w:fldCharType="separate"/></w:r>'
            .'<w:r><w:t>«CustomerName»</w:t></w:r>'
            .'<w:r><w:fldChar w:fldCharType="end"/></w:r>'
            .'</w:p>';
        $doc = new \DOMDocument;
        $doc->loadXML('<w:document xmlns:w="'.OoxmlNs::W.'"><w:body>'.$bodyXml.'</w:body></w:document>');
        $body = $doc->getElementsByTagNameNS(OoxmlNs::W, 'body')->item(0);
        self::assertInstanceOf(\DOMElement::class, $body);

        $reader = new BodyReader;
        $blocks = $reader->read($body);

        $fields = $this->collectByType($blocks, Field::class);
        self::assertCount(1, $fields);
        self::assertStringContainsString('MERGEFIELD', $fields[0]->instruction);
        self::assertStringContainsString('CustomerName', $fields[0]->instruction);

        // «CustomerName» display-text должен быть подавлен (value-phase).
        $paragraphs = array_values(array_filter($blocks, fn ($b) => $b instanceof Paragraph));
        self::assertCount(1, $paragraphs);
        $hasDisplayText = false;
        foreach ($paragraphs[0]->children as $c) {
            if ($c instanceof Run && str_contains($c->text, 'CustomerName')) {
                $hasDisplayText = true;
            }
        }
        self::assertFalse($hasDisplayText, 'value-phase text should be suppressed');
    }

    #[Test]
    public function multi_run_instrText_concatenates(): void
    {
        // Word иногда разбивает instrText на несколько runs.
        $bodyXml =
            '<w:p>'
            .'<w:r><w:fldChar w:fldCharType="begin"/></w:r>'
            .'<w:r><w:instrText xml:space="preserve">MERGEFIELD </w:instrText></w:r>'
            .'<w:r><w:instrText>MyVar</w:instrText></w:r>'
            .'<w:r><w:fldChar w:fldCharType="end"/></w:r>'
            .'</w:p>';
        $doc = new \DOMDocument;
        $doc->loadXML('<w:document xmlns:w="'.OoxmlNs::W.'"><w:body>'.$bodyXml.'</w:body></w:document>');
        $body = $doc->getElementsByTagNameNS(OoxmlNs::W, 'body')->item(0);
        self::assertInstanceOf(\DOMElement::class, $body);

        $reader = new BodyReader;
        $blocks = $reader->read($body);
        $fields = $this->collectByType($blocks, Field::class);
        self::assertCount(1, $fields);
        self::assertSame('MERGEFIELD MyVar', $fields[0]->instruction);
    }

    #[Test]
    public function hyperlink_without_rels_fallbacks_to_flatten(): void
    {
        // Hyperlink с rId но без package → fallback на children
        $bodyXml = '<w:p><w:hyperlink r:id="rIdMissing"'
            .' xmlns:r="'.OoxmlNs::R.'">'
            .'<w:r><w:t>x</w:t></w:r></w:hyperlink></w:p>';
        $doc = new \DOMDocument;
        $doc->loadXML('<w:document xmlns:w="'.OoxmlNs::W.'"><w:body>'.$bodyXml.'</w:body></w:document>');
        $body = $doc->getElementsByTagNameNS(OoxmlNs::W, 'body')->item(0);
        self::assertInstanceOf(\DOMElement::class, $body);

        $reader = new BodyReader; // package = null
        $blocks = $reader->read($body);

        // Без Hyperlink — но children fallback → run с "x"
        $links = $this->collectByType($blocks, Hyperlink::class);
        self::assertCount(0, $links);
        /** @var Paragraph $p */
        $p = $blocks[0];
        self::assertCount(1, $p->children);
        self::assertSame('x', $p->children[0]->text);
    }

    /**
     * @template T
     *
     * @param  list<\Dskripchenko\PhpDocx\Element\BlockElement>  $blocks
     * @param  class-string<T>  $type
     * @return list<T>
     */
    private function collectByType(array $blocks, string $type): array
    {
        $out = [];
        foreach ($blocks as $b) {
            if ($b instanceof Paragraph) {
                foreach ($b->children as $c) {
                    if ($c instanceof $type) {
                        $out[] = $c;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * @return list<\Dskripchenko\PhpDocx\Element\BlockElement>
     */
    private function readBlocks(string $bytes): array
    {
        $pkg = (new DocxPackageReader)->read($bytes);
        $resolver = new StylesResolver($pkg->stylesXml);
        $numbering = (new NumberingReader)->read($pkg->numberingXml);
        $reader = new BodyReader(
            styles: $resolver,
            numbering: $numbering,
            imageReader: null,
            package: $pkg,
            partPath: $pkg->documentPartPath,
        );
        $body = $pkg->documentXml->getElementsByTagNameNS(OoxmlNs::W, 'body')->item(0);
        if (! $body instanceof \DOMElement) {
            throw new \RuntimeException('No body');
        }

        return $reader->read($body);
    }

    private function writeDocx(string $bodyHtml): string
    {
        return (new Word2007Writer)->write((new Converter)->fromHtml($bodyHtml));
    }
}
