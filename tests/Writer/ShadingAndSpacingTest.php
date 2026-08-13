<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Tests\Writer;

use Dskripchenko\PhpDocx\Element\Paragraph;
use Dskripchenko\PhpDocx\Element\Run;
use Dskripchenko\PhpDocx\Html\Converter;
use Dskripchenko\PhpDocx\Style\ParagraphStyle;
use Dskripchenko\PhpDocx\Style\RunStyle;
use Dskripchenko\PhpDocx\Writer\BodyXmlBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Paragraph shading and character spacing.
 *
 * Cells always had shading, paragraphs did not — even though a coloured plate
 * spanning the paragraph width occurs in forms more often than a table made for
 * a single line.
 */
final class ShadingAndSpacingTest extends TestCase
{
    #[Test]
    public function paragraph_shading_emits_shd_in_ppr(): void
    {
        $xml = (new BodyXmlBuilder)->render([
            new Paragraph([new Run('шапка')], new ParagraphStyle(shadingColor: '0f766e')),
        ]);

        self::assertStringContainsString('<w:shd w:val="clear" w:color="auto" w:fill="0f766e"/>', $xml);
    }

    #[Test]
    public function shd_stands_between_pbdr_and_spacing(): void
    {
        // CT_PPrBase is a sequence: unlike w:rPr, the order of elements inside
        // w:pPr is not free — shd has to come after pBdr and before spacing.
        $xml = (new BodyXmlBuilder)->render([
            new Paragraph([new Run('текст')], new ParagraphStyle(
                spaceBeforeTwips: 120,
                shadingColor: 'eeeeee',
            )),
        ]);

        self::assertLessThan(
            strpos($xml, '<w:spacing'),
            strpos($xml, '<w:shd'),
        );
    }

    #[Test]
    public function css_background_on_paragraph_becomes_shading(): void
    {
        $doc = (new Converter)->fromHtml('<p style="background-color:#0f766e">шапка</p>');

        self::assertSame('0f766e', $doc->section->body[0]->style->shadingColor);
    }

    #[Test]
    public function letter_spacing_emits_spacing_in_rpr(): void
    {
        $xml = (new BodyXmlBuilder)->render([
            new Paragraph([new Run('РАЗРЯДКА', new RunStyle(letterSpacingTwips: 40))]),
        ]);

        self::assertStringContainsString('<w:rPr><w:spacing w:val="40"/></w:rPr>', $xml);
    }

    #[Test]
    public function css_letter_spacing_is_parsed(): void
    {
        $doc = (new Converter)->fromHtml('<p><span style="letter-spacing:2pt">текст</span></p>');

        self::assertSame(40, $doc->section->body[0]->children[0]->style->letterSpacingTwips);
    }

    #[Test]
    public function negative_letter_spacing_condenses(): void
    {
        $doc = (new Converter)->fromHtml('<p><span style="letter-spacing:-0.5pt">текст</span></p>');

        self::assertSame(-10, $doc->section->body[0]->children[0]->style->letterSpacingTwips);
    }

    #[Test]
    public function inline_style_no_longer_wipes_inherited_highlight(): void
    {
        // <mark> sets the highlight, and a nested span with its own style keeps it.
        $doc = (new Converter)->fromHtml('<p><mark>жёлтый <span style="color:#111">и тёмный</span></mark></p>');

        $runs = $doc->section->body[0]->children;
        $last = $runs[count($runs) - 1];
        self::assertSame('yellow', $last->style->highlight);
    }
}
