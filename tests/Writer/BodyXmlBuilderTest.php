<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Tests\Writer;

use Dskripchenko\PhpDocx\Element\HorizontalRule;
use Dskripchenko\PhpDocx\Element\Hyperlink;
use Dskripchenko\PhpDocx\Element\LineBreak;
use Dskripchenko\PhpDocx\Element\ListItem;
use Dskripchenko\PhpDocx\Element\ListNode;
use Dskripchenko\PhpDocx\Element\PageBreak;
use Dskripchenko\PhpDocx\Element\Paragraph;
use Dskripchenko\PhpDocx\Element\Run;
use Dskripchenko\PhpDocx\Element\Table;
use Dskripchenko\PhpDocx\Element\TableCell;
use Dskripchenko\PhpDocx\Element\TableRow;
use Dskripchenko\PhpDocx\Style\Alignment;
use Dskripchenko\PhpDocx\Style\Border;
use Dskripchenko\PhpDocx\Style\BorderSet;
use Dskripchenko\PhpDocx\Style\CellStyle;
use Dskripchenko\PhpDocx\Style\ParagraphStyle;
use Dskripchenko\PhpDocx\Style\RunStyle;
use Dskripchenko\PhpDocx\Style\TableStyle;
use Dskripchenko\PhpDocx\Style\VerticalAlign;
use Dskripchenko\PhpDocx\Writer\BodyXmlBuilder;
use Dskripchenko\PhpDocx\Style\RowHeightRule;
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
    public function it_renders_keep_next_after_pstyle_and_before_borders(): void
    {
        $style = new ParagraphStyle(
            alignment: Alignment::Center,
            borders: BorderSet::all(new Border),
            keepWithNext: true,
        );
        $xml = (new BodyXmlBuilder)->render([new Paragraph([new Run('x')], $style, headingLevel: 2)]);

        self::assertStringContainsString('<w:pPr><w:pStyle w:val="Heading2"/><w:keepNext/><w:pBdr>', $xml);
        self::assertLessThan(strpos($xml, '<w:jc'), strpos($xml, '<w:keepNext/>'));

        $plain = (new BodyXmlBuilder)->render([new Paragraph([new Run('x')])]);
        self::assertStringNotContainsString('keepNext', $plain);
    }

    #[Test]
    public function it_renders_keep_lines_after_keep_next_and_before_numpr(): void
    {
        $style = new ParagraphStyle(keepWithNext: true, keepLines: true, borders: BorderSet::all(new Border));
        $xml = (new BodyXmlBuilder)->render([new Paragraph([new Run('x')], $style, headingLevel: 2)]);
        self::assertStringContainsString('<w:pStyle w:val="Heading2"/><w:keepNext/><w:keepLines/><w:pBdr>', $xml);

        $list = (new BodyXmlBuilder)->render([
            new ListNode([
                new ListItem([new Run('x')], style: new ParagraphStyle(keepLines: true)),
            ], ordered: true, levelText: '%1.'),
        ]);
        self::assertStringContainsString('<w:pStyle w:val="ListParagraph"/><w:keepLines/><w:numPr>', $list);

        $plain = (new BodyXmlBuilder)->render([new Paragraph([new Run('x')])]);
        self::assertStringNotContainsString('keepLines', $plain);
    }

    #[Test]
    public function it_renders_an_explicit_style_id_as_pstyle(): void
    {
        $xml = (new BodyXmlBuilder)->render([
            new Paragraph([new Run('x')], new ParagraphStyle(keepWithNext: true), styleId: 'Title'),
        ]);
        self::assertStringContainsString('<w:pPr><w:pStyle w:val="Title"/><w:keepNext/>', $xml);
    }

    #[Test]
    public function it_prefers_the_style_id_over_the_heading_level(): void
    {
        $xml = (new BodyXmlBuilder)->render([
            new Paragraph([new Run('x')], headingLevel: 1, styleId: 'ChapterTitle'),
        ]);
        self::assertStringContainsString('<w:pStyle w:val="ChapterTitle"/>', $xml);
        self::assertStringNotContainsString('Heading1', $xml);
    }

    #[Test]
    public function it_escapes_the_style_id(): void
    {
        $xml = (new BodyXmlBuilder)->render([
            new Paragraph([new Run('x')], styleId: 'A"&B'),
        ]);
        self::assertStringContainsString('<w:pStyle w:val="A&quot;&amp;B"/>', $xml);
    }

    #[Test]
    public function it_renders_page_break_before_and_widow_control_before_numpr(): void
    {
        $style = new ParagraphStyle(keepLines: true, pageBreakBefore: true, widowControl: true, borders: BorderSet::all(new Border));
        $xml = (new BodyXmlBuilder)->render([new Paragraph([new Run('x')], $style, headingLevel: 1)]);
        self::assertStringContainsString(
            '<w:pStyle w:val="Heading1"/><w:keepLines/><w:pageBreakBefore/><w:widowControl/><w:pBdr>',
            $xml,
        );

        $list = (new BodyXmlBuilder)->render([
            new ListNode([
                new ListItem([new Run('x')], style: new ParagraphStyle(pageBreakBefore: true, widowControl: false)),
            ], ordered: true, levelText: '%1.'),
        ]);
        self::assertStringContainsString(
            '<w:pStyle w:val="ListParagraph"/><w:pageBreakBefore/><w:widowControl w:val="0"/><w:numPr>',
            $list,
        );

        $plain = (new BodyXmlBuilder)->render([new Paragraph([new Run('x')])]);
        self::assertStringNotContainsString('pageBreakBefore', $plain);
        self::assertStringNotContainsString('widowControl', $plain);
    }

    #[Test]
    public function it_renders_the_east_asian_font_in_rfonts(): void
    {
        $both = (new BodyXmlBuilder)->render([new Paragraph([
            new Run('x', new RunStyle(fontFamily: 'Arial', fontFamilyEastAsia: 'MS Mincho')),
        ])]);
        self::assertStringContainsString(
            '<w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:eastAsia="MS Mincho" w:cs="Arial"/>',
            $both,
        );

        $only = (new BodyXmlBuilder)->render([new Paragraph([
            new Run('x', new RunStyle(fontFamilyEastAsia: 'SimSun')),
        ])]);
        self::assertStringContainsString('<w:rPr><w:rFonts w:eastAsia="SimSun"/></w:rPr>', $only);

        $latin = (new BodyXmlBuilder)->render([new Paragraph([
            new Run('x', new RunStyle(fontFamily: 'Arial')),
        ])]);
        self::assertStringContainsString('<w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial"/>', $latin);
    }

    #[Test]
    public function it_renders_cant_split_row_before_header_and_height(): void
    {
        $xml = (new BodyXmlBuilder)->render([
            new Table([
                new TableRow([
                    new TableCell([new Paragraph([new Run('H')])]),
                ], isHeader: true, heightTwips: 400, cantSplit: true),
                new TableRow([
                    new TableCell([new Paragraph([new Run('B')])]),
                ]),
            ]),
        ]);

        self::assertStringContainsString('<w:trPr><w:cantSplit/><w:tblHeader/><w:trHeight w:val="400" w:hRule="atLeast"/></w:trPr>', $xml);
        self::assertSame(1, substr_count($xml, '<w:cantSplit/>'));
    }

    #[Test]
    public function it_splits_a_spanning_cell_width_across_its_grid_columns(): void
    {
        $xml = (new BodyXmlBuilder)->render([
            new Table([
                new TableRow([
                    new TableCell([new Paragraph([new Run('A')])], new CellStyle(widthTwips: 2000, gridSpan: 2)),
                    new TableCell([new Paragraph([new Run('B')])], new CellStyle(widthTwips: 2000)),
                ]),
            ]),
        ]);

        self::assertStringContainsString(
            '<w:tblGrid><w:gridCol w:w="1000"/><w:gridCol w:w="1000"/><w:gridCol w:w="2000"/></w:tblGrid>',
            $xml,
        );
    }

    #[Test]
    public function it_takes_grid_widths_from_a_later_row_where_the_columns_are_unspanned(): void
    {
        $xml = (new BodyXmlBuilder)->render([
            new Table([
                new TableRow([
                    new TableCell([new Paragraph([new Run('Title')])], new CellStyle(widthTwips: 5000, gridSpan: 2)),
                ]),
                new TableRow([
                    new TableCell([new Paragraph([new Run('A')])], new CellStyle(widthTwips: 1500)),
                    new TableCell([new Paragraph([new Run('B')])], new CellStyle(widthTwips: 3500)),
                ]),
            ]),
        ]);

        self::assertStringContainsString(
            '<w:tblGrid><w:gridCol w:w="1500"/><w:gridCol w:w="3500"/></w:tblGrid>',
            $xml,
        );
    }

    #[Test]
    public function it_defaults_grid_columns_without_widths_to_2000(): void
    {
        $xml = (new BodyXmlBuilder)->render([
            new Table([
                new TableRow([
                    new TableCell([new Paragraph([new Run('A')])]),
                    new TableCell([new Paragraph([new Run('B')])], new CellStyle(gridSpan: 2)),
                ]),
            ]),
        ]);

        self::assertStringContainsString(
            '<w:tblGrid><w:gridCol w:w="2000"/><w:gridCol w:w="2000"/><w:gridCol w:w="2000"/></w:tblGrid>',
            $xml,
        );
    }

    #[Test]
    public function it_writes_table_indent_after_jc_and_before_borders(): void
    {
        $xml = (new BodyXmlBuilder)->render([
            new Table([
                new TableRow([new TableCell([new Paragraph([new Run('A')])])]),
            ], new TableStyle(borders: BorderSet::none(), alignment: Alignment::Center, indentTwips: 360)),
        ]);

        self::assertStringContainsString(
            '<w:jc w:val="center"/><w:tblInd w:w="360" w:type="dxa"/><w:tblBorders>',
            $xml,
        );
    }

    #[Test]
    public function it_omits_table_indent_by_default(): void
    {
        $xml = (new BodyXmlBuilder)->render([
            new Table([new TableRow([new TableCell([new Paragraph([new Run('A')])])])]),
        ]);

        self::assertStringNotContainsString('<w:tblInd', $xml);
    }

    #[Test]
    public function it_writes_an_exact_row_height(): void
    {
        $xml = (new BodyXmlBuilder)->render([
            new Table([
                new TableRow([new TableCell([new Paragraph([new Run('A')])])], heightTwips: 300, heightRule: RowHeightRule::Exact),
                new TableRow([new TableCell([new Paragraph([new Run('B')])])], heightTwips: 300),
            ]),
        ]);

        self::assertStringContainsString('<w:trHeight w:val="300" w:hRule="exact"/>', $xml);
        self::assertStringContainsString('<w:trHeight w:val="300" w:hRule="atLeast"/>', $xml);
    }

    #[Test]
    public function it_puts_numpr_after_keep_next_in_a_list_item(): void
    {
        $xml = (new BodyXmlBuilder)->render([
            new ListNode([
                new ListItem([new Run('x')], style: new ParagraphStyle(alignment: Alignment::Center, keepWithNext: true)),
            ], ordered: true, levelText: '%1.'),
        ]);

        self::assertStringContainsString('<w:pPr><w:pStyle w:val="ListParagraph"/><w:keepNext/><w:numPr>', $xml);
    }

    #[Test]
    public function it_places_numpr_before_the_other_properties_of_a_list_item(): void
    {
        // CT_PPrBase is a sequence: pStyle, numPr, pBdr, shd, spacing, ind, jc.
        $xml = (new BodyXmlBuilder)->render([
            new ListNode([
                new ListItem([new Run('x')], style: new ParagraphStyle(
                    alignment: Alignment::Center,
                    spaceAfterTwips: 120,
                    borders: BorderSet::all(new Border),
                    shadingColor: 'eeeeee',
                )),
            ], ordered: true, levelText: '%1.'),
        ]);

        $order = ['<w:pStyle', '<w:numPr>', '<w:pBdr>', '<w:shd', '<w:spacing', '<w:jc'];
        $last = -1;
        foreach ($order as $tag) {
            $pos = strpos($xml, $tag);
            self::assertNotFalse($pos, $tag);
            self::assertGreaterThan($last, $pos, $tag);
            $last = $pos;
        }

        $bare = (new BodyXmlBuilder)->render([new ListNode([new ListItem([new Run('x')])], ordered: true, levelText: '%1.')]);
        self::assertStringContainsString('<w:pPr><w:pStyle w:val="ListParagraph"/><w:numPr>', $bare);
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
        // An empty cell (no children) — OOXML requires at least one <w:p>
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
