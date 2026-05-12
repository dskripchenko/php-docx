<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Tests\Html;

use Dskripchenko\PhpDocx\Element\Bookmark;
use Dskripchenko\PhpDocx\Element\Hyperlink;
use Dskripchenko\PhpDocx\Element\Paragraph;
use Dskripchenko\PhpDocx\Html\Converter;
use Dskripchenko\PhpDocx\Writer\BodyXmlBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class InternalLinksTest extends TestCase
{
    #[Test]
    public function hash_href_yields_internal_hyperlink(): void
    {
        $doc = (new Converter)->fromHtml('<p><a href="#section1">go</a></p>');
        /** @var Paragraph $p */
        $p = $doc->section->body[0];
        /** @var Hyperlink $link */
        $link = $p->children[0];
        self::assertInstanceOf(Hyperlink::class, $link);
        self::assertTrue($link->isInternal());
        self::assertSame('section1', $link->anchor);
        self::assertNull($link->href);
    }

    #[Test]
    public function external_url_remains_external(): void
    {
        $doc = (new Converter)->fromHtml('<p><a href="https://example.com">x</a></p>');
        /** @var Paragraph $p */
        $p = $doc->section->body[0];
        /** @var Hyperlink $link */
        $link = $p->children[0];
        self::assertFalse($link->isInternal());
        self::assertSame('https://example.com', $link->href);
    }

    #[Test]
    public function anchor_id_yields_bookmark(): void
    {
        $doc = (new Converter)->fromHtml('<p><a id="section1">heading</a></p>');
        /** @var Paragraph $p */
        $p = $doc->section->body[0];
        /** @var Bookmark $b */
        $b = $p->children[0];
        self::assertInstanceOf(Bookmark::class, $b);
        self::assertSame('section1', $b->name);
    }

    #[Test]
    public function anchor_name_attr_also_yields_bookmark(): void
    {
        $doc = (new Converter)->fromHtml('<p><a name="top">go top</a></p>');
        /** @var Paragraph $p */
        $p = $doc->section->body[0];
        /** @var Bookmark $b */
        $b = $p->children[0];
        self::assertInstanceOf(Bookmark::class, $b);
        self::assertSame('top', $b->name);
    }

    #[Test]
    public function bookmark_name_is_sanitized(): void
    {
        $doc = (new Converter)->fromHtml('<p><a id="my section.1">x</a></p>');
        /** @var Paragraph $p */
        $p = $doc->section->body[0];
        /** @var Bookmark $b */
        $b = $p->children[0];
        self::assertSame('my_section_1', $b->name);
    }

    #[Test]
    public function bookmark_name_starting_with_digit_gets_underscore_prefix(): void
    {
        $doc = (new Converter)->fromHtml('<p><a id="123abc">x</a></p>');
        /** @var Paragraph $p */
        $p = $doc->section->body[0];
        /** @var Bookmark $b */
        $b = $p->children[0];
        self::assertSame('_123abc', $b->name);
    }

    #[Test]
    public function internal_link_xml_uses_w_anchor_not_r_id(): void
    {
        $doc = (new Converter)->fromHtml('<p><a href="#chapter2">jump</a></p>');
        $xml = (new BodyXmlBuilder)->render($doc->section->body);
        self::assertStringContainsString('<w:hyperlink w:anchor="chapter2">', $xml);
        self::assertStringNotContainsString('r:id="rId', $xml);
    }

    #[Test]
    public function bookmark_xml_emits_start_and_end_with_matching_ids(): void
    {
        $doc = (new Converter)->fromHtml('<p><a id="anchor1">x</a> text</p>');
        $xml = (new BodyXmlBuilder)->render($doc->section->body);
        self::assertStringContainsString('<w:bookmarkStart w:id="1" w:name="anchor1"/>', $xml);
        self::assertStringContainsString('<w:bookmarkEnd w:id="1"/>', $xml);
    }

    #[Test]
    public function internal_link_resolves_when_bookmark_exists(): void
    {
        $html = '<p><a id="top">Top</a></p>'
            .'<p>...</p>'
            .'<p><a href="#top">back to top</a></p>';
        $xml = (new BodyXmlBuilder)->render((new Converter)->fromHtml($html)->section->body);
        self::assertStringContainsString('<w:bookmarkStart w:id="1" w:name="top"/>', $xml);
        self::assertStringContainsString('<w:hyperlink w:anchor="top">', $xml);
    }

    #[Test]
    public function anchor_with_both_href_and_id_yields_bookmark_wrapping_link(): void
    {
        $doc = (new Converter)->fromHtml('<p><a href="https://x.com" id="external_link">x</a></p>');
        /** @var Paragraph $p */
        $p = $doc->section->body[0];
        /** @var Bookmark $b */
        $b = $p->children[0];
        self::assertInstanceOf(Bookmark::class, $b);
        self::assertSame('external_link', $b->name);
        /** @var Hyperlink $inner */
        $inner = $b->children[0];
        self::assertInstanceOf(Hyperlink::class, $inner);
        self::assertSame('https://x.com', $inner->href);
    }
}
