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
 * Межстрочный интервал доезжает до HTML.
 *
 * Раньше он терялся вовсе: документ с полуторным интервалом печатался
 * плотным, и расхождение с оригиналом копилось к концу.
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
        // 360/240 = полтора одинарных; в CSS множитель считается от кегля,
        // поэтому одинарный берётся за 1.2 кегля.
        $html = $this->html(new ParagraphStyle(lineSpacingTwips: 360, lineSpacingRule: 'auto'));

        self::assertStringContainsString('line-height:1.8', $html);
    }

    #[Test]
    public function single_spacing_is_not_carried_at_all(): void
    {
        // У движка печати свой одинарный интервал, и он ближе к типографской
        // норме, чем любое наше приближение: перенос 240 → 1.0 сжимал строки.
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
