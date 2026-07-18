<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Tests\Html;

use Dskripchenko\PhpDocx\Html\Converter;
use Dskripchenko\PhpDocx\Html\CssInlinerPreprocessor;
use Dskripchenko\PhpDocx\Html\HtmlPreprocessor;
use Dskripchenko\PhpDocx\Html\Serializer;
use Dskripchenko\PhpDocx\Writer\Word2007Writer;
use Dskripchenko\PhpDocx\Reader\DocxReader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The HTML-input seam: fromHtmlWithStyles() runs the document through an
 * HtmlPreprocessor first, so `<style>` blocks and classes become the
 * inline styles the converter understands. The default preprocessor
 * delegates to the optional css-to-inline-styles package (dev-dependency
 * here, `suggest` for consumers).
 */
final class FromHtmlWithStylesTest extends TestCase
{
    private const HTML = <<<'HTML'
        <html><head><style>
            .warn { color: #c0392b; }
            p { font-size: 14px; }
        </style></head>
        <body><p class="warn">Styled via a class.</p></body></html>
        HTML;

    #[Test]
    public function style_blocks_and_classes_are_inlined_before_conversion(): void
    {
        $doc = (new Converter)->fromHtmlWithStyles(self::HTML);
        $bytes = (new Word2007Writer)->write($doc);

        $roundTrip = (new Serializer)->serialize((new DocxReader)->read($bytes));
        self::assertStringContainsString('Styled via a class.', $roundTrip->bodyHtml);
        // The class colour must have survived as a real run colour.
        self::assertMatchesRegularExpression('@color:#c0392b@i', $roundTrip->bodyHtml);
    }

    #[Test]
    public function plain_from_html_ignores_style_blocks(): void
    {
        $doc = (new Converter)->fromHtml('<p class="warn">x</p>');
        $bytes = (new Word2007Writer)->write($doc);
        $roundTrip = (new Serializer)->serialize((new DocxReader)->read($bytes));

        self::assertStringNotContainsString('c0392b', $roundTrip->bodyHtml);
    }

    #[Test]
    public function custom_preprocessor_wins_over_the_default(): void
    {
        $custom = new class implements HtmlPreprocessor
        {
            public function preprocess(string $html): string
            {
                return str_replace('REPLACE-ME', 'replaced by preprocessor', $html);
            }
        };

        $doc = (new Converter(preprocessor: $custom))
            ->fromHtmlWithStyles('<p>REPLACE-ME</p>');
        $bytes = (new Word2007Writer)->write($doc);
        $roundTrip = (new Serializer)->serialize((new DocxReader)->read($bytes));

        self::assertStringContainsString('replaced by preprocessor', $roundTrip->bodyHtml);
    }

    #[Test]
    public function inliner_preprocessor_applies_extra_css(): void
    {
        $pre = new CssInlinerPreprocessor(extraCss: 'p { color: #123456; }');

        self::assertStringContainsString('#123456', $pre->preprocess('<p>x</p>'));
    }
}
