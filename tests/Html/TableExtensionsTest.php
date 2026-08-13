<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Tests\Html;

use Dskripchenko\PhpDocx\Element\Table;
use Dskripchenko\PhpDocx\Html\Converter;
use Dskripchenko\PhpDocx\Writer\BodyXmlBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for table extensions: <caption>, <colgroup>/<col>.
 */
final class TableExtensionsTest extends TestCase
{
    #[Test]
    public function caption_is_parsed_into_table_field(): void
    {
        $doc = (new Converter)->fromHtml('<table><caption>My table</caption><tr><td>A</td></tr></table>');
        /** @var Table $t */
        $t = $doc->section->body[0];
        self::assertSame('My table', $t->caption);
    }

    #[Test]
    public function caption_renders_as_paragraph_before_table(): void
    {
        $doc = (new Converter)->fromHtml('<table><caption>Sales 2026</caption><tr><td>x</td></tr></table>');
        $xml = (new BodyXmlBuilder)->render($doc->section->body);

        // The caption paragraph has to come BEFORE <w:tbl>.
        $capPos = strpos($xml, 'Sales 2026');
        $tblPos = strpos($xml, '<w:tbl>');
        self::assertNotFalse($capPos);
        self::assertNotFalse($tblPos);
        self::assertLessThan($tblPos, $capPos);
        // The caption uses the "Caption" style
        self::assertStringContainsString('<w:pStyle w:val="Caption"/>', $xml);
    }

    #[Test]
    public function empty_caption_is_omitted(): void
    {
        $doc = (new Converter)->fromHtml('<table><caption></caption><tr><td>x</td></tr></table>');
        /** @var Table $t */
        $t = $doc->section->body[0];
        self::assertNull($t->caption);
    }

    #[Test]
    public function colgroup_with_col_width_attribute_sets_grid_columns(): void
    {
        $html = '<table>'
            .'<colgroup><col width="100"><col width="300"><col width="200"></colgroup>'
            .'<tr><td>A</td><td>B</td><td>C</td></tr>'
            .'</table>';
        $doc = (new Converter)->fromHtml($html);
        /** @var Table $t */
        $t = $doc->section->body[0];

        // width=100 without a unit → px → 1500 twips
        self::assertSame([1500, 4500, 3000], $t->gridColumnsTwips);
    }

    #[Test]
    public function colgroup_css_width_pt(): void
    {
        $html = '<table>'
            .'<colgroup><col style="width: 100pt"/><col style="width: 200pt"/></colgroup>'
            .'<tr><td>A</td><td>B</td></tr>'
            .'</table>';
        $doc = (new Converter)->fromHtml($html);
        /** @var Table $t */
        $t = $doc->section->body[0];

        self::assertSame([2000, 4000], $t->gridColumnsTwips);
    }

    #[Test]
    public function colgroup_uses_gridcol_widths_in_tblGrid_xml(): void
    {
        $html = '<table>'
            .'<colgroup><col width="100"/><col width="200"/></colgroup>'
            .'<tr><td>A</td><td>B</td></tr>'
            .'</table>';
        $doc = (new Converter)->fromHtml($html);
        $xml = (new BodyXmlBuilder)->render($doc->section->body);

        self::assertStringContainsString('<w:gridCol w:w="1500"/>', $xml);
        self::assertStringContainsString('<w:gridCol w:w="3000"/>', $xml);
    }

    #[Test]
    public function caption_inside_tr_does_not_confuse_row_parser(): void
    {
        // A <caption> among the children of <table> must not become a TableRow.
        $doc = (new Converter)->fromHtml('<table><caption>x</caption><tr><td>A</td></tr></table>');
        /** @var Table $t */
        $t = $doc->section->body[0];
        self::assertCount(1, $t->rows);
    }
}
