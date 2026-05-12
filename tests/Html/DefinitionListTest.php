<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Tests\Html;

use Dskripchenko\PhpDocx\Element\Paragraph;
use Dskripchenko\PhpDocx\Element\Run;
use Dskripchenko\PhpDocx\Html\Converter;
use Dskripchenko\PhpDocx\Writer\BodyXmlBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DefinitionListTest extends TestCase
{
    #[Test]
    public function dl_produces_pairs_of_paragraphs(): void
    {
        $html = '<dl>'
            .'<dt>HTML</dt><dd>HyperText Markup Language</dd>'
            .'<dt>CSS</dt><dd>Cascading Style Sheets</dd>'
            .'</dl>';
        $doc = (new Converter)->fromHtml($html);
        $blocks = $doc->section->body;

        self::assertCount(4, $blocks);
        self::assertContainsOnlyInstancesOf(Paragraph::class, $blocks);
    }

    #[Test]
    public function dt_is_rendered_bold(): void
    {
        $doc = (new Converter)->fromHtml('<dl><dt>Term</dt><dd>Definition</dd></dl>');
        /** @var Paragraph $dt */
        $dt = $doc->section->body[0];
        /** @var Run $run */
        $run = $dt->children[0];
        self::assertTrue($run->style->bold);
    }

    #[Test]
    public function dd_is_indented(): void
    {
        $doc = (new Converter)->fromHtml('<dl><dt>X</dt><dd>Y</dd></dl>');
        /** @var Paragraph $dd */
        $dd = $doc->section->body[1];
        self::assertSame(720, $dd->style->indentLeftTwips);
    }

    #[Test]
    public function dd_text_is_not_bold(): void
    {
        $doc = (new Converter)->fromHtml('<dl><dt>X</dt><dd>plain</dd></dl>');
        /** @var Paragraph $dd */
        $dd = $doc->section->body[1];
        /** @var Run $run */
        $run = $dd->children[0];
        self::assertFalse($run->style->bold);
    }

    #[Test]
    public function dl_emits_two_paragraphs_in_xml(): void
    {
        $doc = (new Converter)->fromHtml('<dl><dt>Apple</dt><dd>fruit</dd></dl>');
        $xml = (new BodyXmlBuilder)->render($doc->section->body);
        self::assertStringContainsString('Apple', $xml);
        self::assertStringContainsString('fruit', $xml);
        self::assertSame(2, substr_count($xml, '<w:p>'));
    }
}
