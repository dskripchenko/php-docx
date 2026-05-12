<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Tests\Writer;

use Dskripchenko\PhpDocx\Element\HorizontalRule;
use Dskripchenko\PhpDocx\Element\Hyperlink;
use Dskripchenko\PhpDocx\Element\LineBreak;
use Dskripchenko\PhpDocx\Element\PageBreak;
use Dskripchenko\PhpDocx\Element\Paragraph;
use Dskripchenko\PhpDocx\Element\Run;
use Dskripchenko\PhpDocx\Element\Table;
use Dskripchenko\PhpDocx\Element\TableCell;
use Dskripchenko\PhpDocx\Element\TableRow;
use Dskripchenko\PhpDocx\Style\Alignment;
use Dskripchenko\PhpDocx\Style\BorderSet;
use Dskripchenko\PhpDocx\Style\CellStyle;
use Dskripchenko\PhpDocx\Style\ParagraphStyle;
use Dskripchenko\PhpDocx\Style\RunStyle;
use Dskripchenko\PhpDocx\Style\TableStyle;
use Dskripchenko\PhpDocx\Style\VerticalAlign;
use Dskripchenko\PhpDocx\Writer\BodyXmlBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BodyXmlBuilderTest extends TestCase
{
    #[Test]
    public function it_renders_empty_blocks_as_empty_string(): void
    {
        self::assertSame('', (new BodyXmlBuilder)->render([]));
    }

    #[Test]
    public function it_renders_paragraph_with_text(): void
    {
        $xml = (new BodyXmlBuilder)->render([
            new Paragraph([new Run('Hello world')]),
        ]);

        self::assertStringContainsString('<w:p>', $xml);
        self::assertStringContainsString('<w:r>', $xml);
        self::assertStringContainsString('<w:t xml:space="preserve">Hello world</w:t>', $xml);
    }

    #[Test]
    public function it_escapes_special_characters_in_text(): void
    {
        $xml = (new BodyXmlBuilder)->render([
            new Paragraph([new Run('A & B < C > "D"')]),
        ]);

        self::assertStringContainsString('A &amp; B &lt; C &gt; &quot;D&quot;', $xml);
        self::assertStringNotContainsString('A & B', $xml);
    }

    #[Test]
    public function it_renders_run_style_bold_italic_color_size(): void
    {
        $xml = (new BodyXmlBuilder)->render([
            new Paragraph([
                new Run('X', new RunStyle(
                    sizeHalfPoints: 32,
                    color: '14b8a6',
                    bold: true,
                    italic: true,
                )),
            ]),
        ]);

        self::assertStringContainsString('<w:b/>', $xml);
        self::assertStringContainsString('<w:i/>', $xml);
        self::assertStringContainsString('<w:color w:val="14b8a6"/>', $xml);
        self::assertStringContainsString('<w:sz w:val="32"/>', $xml);
    }

    #[Test]
    public function it_renders_paragraph_alignment(): void
    {
        $xml = (new BodyXmlBuilder)->render([
            new Paragraph(
                children: [new Run('X')],
                style: new ParagraphStyle(alignment: Alignment::Center),
            ),
        ]);

        self::assertStringContainsString('<w:jc w:val="center"/>', $xml);
    }

    #[Test]
    public function it_renders_heading_with_pStyle(): void
    {
        $xml = (new BodyXmlBuilder)->render([
            new Paragraph(
                children: [new Run('Title')],
                headingLevel: 1,
            ),
        ]);

        self::assertStringContainsString('<w:pStyle w:val="Heading1"/>', $xml);
    }

    #[Test]
    public function it_renders_line_break_inside_paragraph(): void
    {
        $xml = (new BodyXmlBuilder)->render([
            new Paragraph([new Run('A'), new LineBreak, new Run('B')]),
        ]);

        self::assertStringContainsString('<w:br/>', $xml);
    }

    #[Test]
    public function it_renders_page_break(): void
    {
        $xml = (new BodyXmlBuilder)->render([new PageBreak]);
        self::assertStringContainsString('<w:br w:type="page"/>', $xml);
    }

    #[Test]
    public function it_renders_horizontal_rule_as_paragraph_with_bottom_border(): void
    {
        $xml = (new BodyXmlBuilder)->render([new HorizontalRule]);
        self::assertStringContainsString('<w:pBdr>', $xml);
        self::assertStringContainsString('<w:bottom', $xml);
    }

    #[Test]
    public function it_renders_table_with_grid_and_cells(): void
    {
        $xml = (new BodyXmlBuilder)->render([
            new Table([
                new TableRow([
                    new TableCell([new Paragraph([new Run('A')])]),
                    new TableCell([new Paragraph([new Run('B')])]),
                ]),
            ]),
        ]);

        self::assertStringContainsString('<w:tbl>', $xml);
        self::assertStringContainsString('<w:tblGrid>', $xml);
        self::assertStringContainsString('<w:gridCol', $xml);
        self::assertStringContainsString('<w:tr>', $xml);
        self::assertStringContainsString('<w:tc>', $xml);
        self::assertStringContainsString('A', $xml);
        self::assertStringContainsString('B', $xml);
    }

    #[Test]
    public function it_renders_table_header_row(): void
    {
        $xml = (new BodyXmlBuilder)->render([
            new Table([
                new TableRow([
                    new TableCell([new Paragraph([new Run('H')])]),
                ], isHeader: true),
            ]),
        ]);

        self::assertStringContainsString('<w:tblHeader/>', $xml);
    }

    #[Test]
    public function it_renders_cell_style_with_width_padding_bg(): void
    {
        $xml = (new BodyXmlBuilder)->render([
            new Table([
                new TableRow([
                    new TableCell(
                        children: [new Paragraph([new Run('X')])],
                        style: new CellStyle(
                            widthPercent: 1800,
                            paddingTopTwips: 80,
                            backgroundColor: '14b8a6',
                            verticalAlign: VerticalAlign::Center,
                        ),
                    ),
                ]),
            ]),
        ]);

        self::assertStringContainsString('<w:tcW w:w="1800" w:type="pct"/>', $xml);
        self::assertStringContainsString('<w:tcMar>', $xml);
        self::assertStringContainsString('<w:shd w:val="clear" w:color="auto" w:fill="14b8a6"/>', $xml);
        self::assertStringContainsString('<w:vAlign w:val="center"/>', $xml);
    }

    #[Test]
    public function it_renders_cell_gridSpan_for_colspan(): void
    {
        $xml = (new BodyXmlBuilder)->render([
            new Table([
                new TableRow([
                    new TableCell(
                        children: [new Paragraph([new Run('X')])],
                        style: new CellStyle(gridSpan: 3),
                    ),
                ]),
            ]),
        ]);

        self::assertStringContainsString('<w:gridSpan w:val="3"/>', $xml);
    }

    #[Test]
    public function it_renders_table_borders_none_with_w_val_none(): void
    {
        $xml = (new BodyXmlBuilder)->render([
            new Table(
                rows: [new TableRow([new TableCell([new Paragraph([new Run('X')])])])],
                style: new TableStyle(borders: BorderSet::none()),
            ),
        ]);

        self::assertStringContainsString('<w:tblBorders>', $xml);
        self::assertStringContainsString('<w:top w:val="none"', $xml);
    }

    #[Test]
    public function it_registers_hyperlink_rel_and_emits_r_id(): void
    {
        $builder = new BodyXmlBuilder;
        $xml = $builder->render([
            new Paragraph([
                new Hyperlink('https://example.com', [new Run('Link')]),
            ]),
        ]);

        self::assertStringContainsString('<w:hyperlink r:id="rId1">', $xml);
        $relations = $builder->relationships()->relationships();
        self::assertCount(1, $relations);
        self::assertSame('https://example.com', $relations[0]['target']);
        self::assertSame('External', $relations[0]['targetMode']);
    }

    #[Test]
    public function it_emits_empty_cell_as_single_paragraph(): void
    {
        // Пустая cell (без children) — OOXML требует хотя бы один <w:p>
        $xml = (new BodyXmlBuilder)->render([
            new Table([
                new TableRow([
                    new TableCell([]),
                ]),
            ]),
        ]);

        self::assertStringContainsString('<w:tc>', $xml);
        self::assertStringContainsString('<w:p/>', $xml);
    }
}
