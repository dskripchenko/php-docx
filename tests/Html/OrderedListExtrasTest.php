<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Tests\Html;

use Dskripchenko\PhpDocx\Element\ListFormat;
use Dskripchenko\PhpDocx\Element\ListNode;
use Dskripchenko\PhpDocx\Html\Converter;
use Dskripchenko\PhpDocx\Writer\BodyXmlBuilder;
use Dskripchenko\PhpDocx\Writer\NumberingXmlBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OrderedListExtrasTest extends TestCase
{
    #[Test]
    public function ol_type_a_yields_lower_letter_format(): void
    {
        $doc = (new Converter)->fromHtml('<ol type="a"><li>x</li><li>y</li></ol>');
        /** @var ListNode $list */
        $list = $doc->section->body[0];
        self::assertSame(ListFormat::LowerLetter, $list->effectiveFormat());
        self::assertSame(1, $list->startAt);
    }

    #[Test]
    public function ol_type_I_yields_upper_roman_format(): void
    {
        $doc = (new Converter)->fromHtml('<ol type="I"><li>x</li></ol>');
        /** @var ListNode $list */
        $list = $doc->section->body[0];
        self::assertSame(ListFormat::UpperRoman, $list->effectiveFormat());
    }

    #[Test]
    public function ol_start_attribute_sets_startAt(): void
    {
        $doc = (new Converter)->fromHtml('<ol start="5"><li>x</li></ol>');
        /** @var ListNode $list */
        $list = $doc->section->body[0];
        self::assertSame(5, $list->startAt);
    }

    #[Test]
    public function li_value_on_first_item_sets_startAt(): void
    {
        $doc = (new Converter)->fromHtml('<ol><li value="7">x</li></ol>');
        /** @var ListNode $list */
        $list = $doc->section->body[0];
        self::assertSame(7, $list->startAt);
    }

    #[Test]
    public function custom_format_registers_new_numId_in_numbering_xml(): void
    {
        $numbering = new NumberingXmlBuilder;
        $builder = new BodyXmlBuilder(numbering: $numbering);
        $doc = (new Converter)->fromHtml('<ol type="A" start="3"><li>x</li></ol>');
        $bodyXml = $builder->render($doc->section->body);

        // The numId has to be > 2 (above the standard bullet=1, decimal=2).
        self::assertMatchesRegularExpression('/<w:numId w:val="([3-9]|\d{2,})"\/>/', $bodyXml);

        $numXml = $numbering->render();
        // It has to carry the upperLetter formatting and start=3.
        self::assertStringContainsString('<w:numFmt w:val="upperLetter"/>', $numXml);
        self::assertStringContainsString('<w:start w:val="3"/>', $numXml);
    }

    #[Test]
    public function bullet_and_decimal_use_standard_fixed_ids(): void
    {
        $numbering = new NumberingXmlBuilder;
        self::assertSame(1, $numbering->instanceFor(ListFormat::Bullet));
        self::assertSame(2, $numbering->instanceFor(ListFormat::Decimal, 1));
        // Decimal with start>1 is already a custom instance.
        $custom = $numbering->instanceFor(ListFormat::Decimal, 5);
        self::assertGreaterThan(2, $custom);
    }

    #[Test]
    public function instanceFor_is_idempotent_per_combo(): void
    {
        $numbering = new NumberingXmlBuilder;
        $a = $numbering->instanceFor(ListFormat::LowerLetter, 1);
        $b = $numbering->instanceFor(ListFormat::LowerLetter, 1);
        self::assertSame($a, $b);
    }
}
