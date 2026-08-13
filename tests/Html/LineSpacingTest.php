<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Tests\Html;

use Dskripchenko\PhpDocx\Element\Paragraph;
use Dskripchenko\PhpDocx\Element\Run;
use Dskripchenko\PhpDocx\Section;
use Dskripchenko\PhpDocx\Html\Serializer;
use Dskripchenko\PhpDocx\Document;
use Dskripchenko\PhpDocx\Style\ParagraphStyle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The line spacing makes it all the way into the HTML.
 *
 * It used to be lost entirely: a document set one-and-a-half printed tight, and
 * the divergence from the original accumulated towards the end.
 */
final class LineSpacingTest extends TestCase
{
    private function html(ParagraphStyle $style): string
    {
        $document = new Document(new Section(body: [new Paragraph([new Run('текст')], $style)]));

        return (new Serializer)->serialize($document)->bodyHtml;
    }

    #[Test]
    public function one_and_a_half_spacing_becomes_a_css_multiplier(): void
    {
        // 360/240 = one and a half of single; in CSS the multiplier counts from
        // the font size, so single is taken as 1.2 of it.
        $html = $this->html(new ParagraphStyle(lineSpacingTwips: 360, lineSpacingRule: 'auto'));

        self::assertStringContainsString('line-height:1.8', $html);
    }

    #[Test]
    public function single_spacing_is_not_carried_at_all(): void
    {
        // The print engine has its own idea of single spacing, and it is closer
        // to the typographic norm than any approximation of ours: mapping
        // 240 → 1.0 squeezed the lines.
        $html = $this->html(new ParagraphStyle(lineSpacingTwips: 240, lineSpacingRule: 'auto'));

        self::assertStringNotContainsString('line-height', $html);
    }

    #[Test]
    public function exact_spacing_becomes_absolute_points(): void
    {
        $html = $this->html(new ParagraphStyle(lineSpacingTwips: 300, lineSpacingRule: 'exact'));

        self::assertStringContainsString('line-height:15pt', $html);
    }
}
