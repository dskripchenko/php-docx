<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Tests\Html;

use Dskripchenko\PhpDocx\Element\Paragraph;
use Dskripchenko\PhpDocx\Element\Run;
use Dskripchenko\PhpDocx\Html\Converter;
use Dskripchenko\PhpDocx\Writer\BodyXmlBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the inline tags added in batch 1 of the «12 priority items» phase:
 *   <small>, <mark>, <code>, <kbd>, <samp>, <var>, <cite>, <dfn>, <q>,
 *   <pre> + image alt.
 */
final class InlineTagsTest extends TestCase
{
    #[Test]
    public function mark_emits_highlight(): void
    {
        $doc = (new Converter)->fromHtml('<p>Hello <mark>world</mark></p>');
        $xml = (new BodyXmlBuilder)->render($doc->section->body);

        self::assertStringContainsString('<w:highlight w:val="yellow"/>', $xml);
    }

    #[Test]
    public function code_kbd_samp_use_courier_new(): void
    {
        foreach (['code', 'kbd', 'samp'] as $tag) {
            $doc = (new Converter)->fromHtml("<p>x <{$tag}>monos</{$tag}> y</p>");
            $xml = (new BodyXmlBuilder)->render($doc->section->body);
            self::assertStringContainsString('w:ascii="Courier New"', $xml, "tag {$tag}");
        }
    }

    #[Test]
    public function small_reduces_font_size(): void
    {
        $doc = (new Converter)->fromHtml('<p style="font-size: 24pt">big <small>tiny</small></p>');
        $xml = (new BodyXmlBuilder)->render($doc->section->body);

        // 24pt = 48 half-points; small × 0.83 ≈ 40
        self::assertMatchesRegularExpression('/<w:sz w:val="40"\/>/', $xml);
    }

    #[Test]
    public function cite_dfn_var_render_italic(): void
    {
        foreach (['cite', 'dfn', 'var'] as $tag) {
            $doc = (new Converter)->fromHtml("<p><{$tag}>x</{$tag}></p>");
            $xml = (new BodyXmlBuilder)->render($doc->section->body);
            self::assertMatchesRegularExpression('/<w:i[ \/]/', $xml, $tag);
        }
    }

    #[Test]
    public function pre_preserves_whitespace_and_uses_courier(): void
    {
        $doc = (new Converter)->fromHtml("<pre>line1\n    line2\n  end</pre>");
        $body = $doc->section->body;

        self::assertCount(1, $body);
        self::assertInstanceOf(Paragraph::class, $body[0]);
        /** @var Paragraph $p */
        $p = $body[0];
        self::assertInstanceOf(Run::class, $p->children[0]);
        // The whitespace is preserved (newlines and multiple spaces)
        self::assertStringContainsString("\n    line2", $p->children[0]->text);
        self::assertSame('Courier New', $p->children[0]->style->fontFamily);
    }

    #[Test]
    public function img_alt_text_emitted_in_docPr_descr(): void
    {
        $pngBin = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
        $html = '<p><img src="data:image/png;base64,'.base64_encode($pngBin).'" width="50" height="50" alt="Логотип компании"></p>';
        $doc = (new Converter)->fromHtml($html);
        $xml = (new BodyXmlBuilder)->render($doc->section->body);

        self::assertStringContainsString('descr="Логотип компании"', $xml);
    }

    #[Test]
    public function semantic_blocks_render_as_div_children(): void
    {
        foreach (['section', 'article', 'aside', 'header', 'footer', 'nav', 'main'] as $tag) {
            $doc = (new Converter)->fromHtml("<{$tag}><p>x</p></{$tag}>");
            self::assertCount(1, $doc->section->body, $tag);
            self::assertInstanceOf(Paragraph::class, $doc->section->body[0], $tag);
        }
    }
}
