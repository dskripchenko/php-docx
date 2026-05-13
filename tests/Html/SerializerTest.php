<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Tests\Html;

use Dskripchenko\PhpDocx\Document;
use Dskripchenko\PhpDocx\Element\ListFormat;
use Dskripchenko\PhpDocx\Element\ListItem;
use Dskripchenko\PhpDocx\Element\ListNode;
use Dskripchenko\PhpDocx\Element\Paragraph;
use Dskripchenko\PhpDocx\Element\Run;
use Dskripchenko\PhpDocx\Element\Table;
use Dskripchenko\PhpDocx\Element\TableCell;
use Dskripchenko\PhpDocx\Element\TableRow;
use Dskripchenko\PhpDocx\Html\Converter;
use Dskripchenko\PhpDocx\Html\Serializer;
use Dskripchenko\PhpDocx\Reader\DocxReader;
use Dskripchenko\PhpDocx\Section;
use Dskripchenko\PhpDocx\Style\Alignment;
use Dskripchenko\PhpDocx\Style\CellStyle;
use Dskripchenko\PhpDocx\Style\ParagraphStyle;
use Dskripchenko\PhpDocx\Style\RunStyle;
use Dskripchenko\PhpDocx\Writer\Word2007Writer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SerializerTest extends TestCase
{
    #[Test]
    public function simple_paragraph_serializes_to_p_tag(): void
    {
        $doc = new Document(section: new Section([
            new Paragraph([new Run('hello')]),
        ]));
        $imported = (new Serializer)->serialize($doc);

        self::assertSame('<p>hello</p>', $imported->bodyHtml);
    }

    #[Test]
    public function heading_paragraph_serializes_to_hN(): void
    {
        $doc = new Document(section: new Section([
            new Paragraph([new Run('Title')], headingLevel: 2),
        ]));
        $imported = (new Serializer)->serialize($doc);
        self::assertSame('<h2>Title</h2>', $imported->bodyHtml);
    }

    #[Test]
    public function bold_italic_underline_yields_semantic_tags(): void
    {
        $doc = new Document(section: new Section([
            new Paragraph([
                new Run('a', new RunStyle(bold: true)),
                new Run('b', new RunStyle(italic: true)),
                new Run('c', new RunStyle(underline: true)),
            ]),
        ]));
        $imported = (new Serializer)->serialize($doc);
        self::assertStringContainsString('<strong>a</strong>', $imported->bodyHtml);
        self::assertStringContainsString('<em>b</em>', $imported->bodyHtml);
        self::assertStringContainsString('<u>c</u>', $imported->bodyHtml);
    }

    #[Test]
    public function color_and_font_yield_inline_span_style(): void
    {
        $doc = new Document(section: new Section([
            new Paragraph([
                new Run('x', new RunStyle(sizeHalfPoints: 32, color: 'ff0000', fontFamily: 'Arial')),
            ]),
        ]));
        $html = (new Serializer)->serialize($doc)->bodyHtml;
        self::assertStringContainsString('<span', $html);
        self::assertStringContainsString('color:#ff0000', $html);
        self::assertStringContainsString('font-family:Arial', $html);
        self::assertStringContainsString('font-size:16pt', $html);
    }

    #[Test]
    public function alignment_center_emits_text_align(): void
    {
        $doc = new Document(section: new Section([
            new Paragraph(
                [new Run('x')],
                new ParagraphStyle(alignment: Alignment::Center),
            ),
        ]));
        $html = (new Serializer)->serialize($doc)->bodyHtml;
        self::assertStringContainsString('text-align:center', $html);
    }

    #[Test]
    public function bullet_list_serializes_to_ul(): void
    {
        $doc = new Document(section: new Section([
            new ListNode([
                new ListItem([new Run('a')]),
                new ListItem([new Run('b')]),
            ]),
        ]));
        $html = (new Serializer)->serialize($doc)->bodyHtml;
        self::assertStringContainsString('<ul>', $html);
        self::assertStringContainsString('<li>a</li>', $html);
        self::assertStringContainsString('<li>b</li>', $html);
    }

    #[Test]
    public function ordered_list_with_type_a_and_start(): void
    {
        $doc = new Document(section: new Section([
            new ListNode(
                items: [new ListItem([new Run('x')]), new ListItem([new Run('y')])],
                ordered: true,
                format: ListFormat::LowerLetter,
                startAt: 3,
            ),
        ]));
        $html = (new Serializer)->serialize($doc)->bodyHtml;
        self::assertStringContainsString('<ol type="a" start="3">', $html);
    }

    #[Test]
    public function table_with_rowspan_colspan(): void
    {
        $doc = new Document(section: new Section([
            new Table([
                new TableRow([
                    new TableCell([new Paragraph([new Run('m')])], new CellStyle(rowSpan: 2)),
                    new TableCell([new Paragraph([new Run('a')])], new CellStyle(gridSpan: 2)),
                ]),
                new TableRow([
                    new TableCell([new Paragraph([])], new CellStyle(vMergeContinue: true)),
                    new TableCell([new Paragraph([new Run('b')])]),
                    new TableCell([new Paragraph([new Run('c')])]),
                ]),
            ]),
        ]));
        $html = (new Serializer)->serialize($doc)->bodyHtml;
        self::assertStringContainsString('rowspan="2"', $html);
        self::assertStringContainsString('colspan="2"', $html);
        // vMergeContinue cell должен быть dropped в HTML.
        self::assertStringNotContainsString('vMergeContinue', $html);
    }

    #[Test]
    public function image_emits_data_url_and_collects_media(): void
    {
        // PNG bytes
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        $img = new \Dskripchenko\PhpDocx\Element\Image(
            binary: $bytes,
            format: \Dskripchenko\PhpDocx\Element\ImageFormat::Png,
            widthEmu: 95250,
            heightEmu: 95250,
            altText: 'logo',
        );
        $doc = new Document(section: new Section([
            new Paragraph([$img]),
        ]));
        $imported = (new Serializer)->serialize($doc);

        self::assertStringContainsString('data:image/png;base64,', $imported->bodyHtml);
        self::assertStringContainsString('alt="logo"', $imported->bodyHtml);
        self::assertStringContainsString('width="10"', $imported->bodyHtml);
        self::assertArrayHasKey('img1.png', $imported->media);
        self::assertSame($bytes, $imported->media['img1.png']);
    }

    #[Test]
    public function page_field_emits_custom_tag(): void
    {
        $doc = new Document(section: new Section([
            new Paragraph([
                new Run('page '),
                \Dskripchenko\PhpDocx\Element\Field::page(),
                new Run(' of '),
                \Dskripchenko\PhpDocx\Element\Field::pageTotal(),
            ]),
        ]));
        $html = (new Serializer)->serialize($doc)->bodyHtml;
        self::assertStringContainsString('<page-number/>', $html);
        self::assertStringContainsString('<page-total/>', $html);
    }

    #[Test]
    public function mergefield_emits_var_tag(): void
    {
        $doc = new Document(section: new Section([
            new Paragraph([
                new \Dskripchenko\PhpDocx\Element\Field('MERGEFIELD CustomerName \\* MERGEFORMAT'),
            ]),
        ]));
        $html = (new Serializer)->serialize($doc)->bodyHtml;
        self::assertStringContainsString('<var data-name="CustomerName">', $html);
    }

    #[Test]
    public function full_roundtrip_html_writer_reader_serializer(): void
    {
        $originalHtml =
            '<h1>Title</h1>'
            .'<p style="text-align: center"><strong>Bold</strong> and <em>italic</em></p>'
            .'<ul><li>a</li><li>b</li></ul>'
            .'<table><tr><th>X</th></tr><tr><td>1</td></tr></table>';

        $writer = new Word2007Writer;
        $bytes = $writer->write((new Converter)->fromHtml($originalHtml));
        $doc = (new DocxReader)->read($bytes);
        $imported = (new Serializer)->serialize($doc);

        // bodyHtml должен содержать ключевые элементы (с возможным
        // style-атрибутом — поэтому ищем prefix без `>`).
        self::assertStringContainsString('<h1', $imported->bodyHtml);
        self::assertStringContainsString('<strong>Bold</strong>', $imported->bodyHtml);
        self::assertStringContainsString('<em>italic</em>', $imported->bodyHtml);
        self::assertStringContainsString('<ul>', $imported->bodyHtml);
        self::assertStringContainsString('<table', $imported->bodyHtml);
        // Cell content; semantic th/td может потеряться без <thead>-обёртки
        // в исходном HTML (Converter limitation, не Serializer).
        self::assertStringContainsString('>X<', $imported->bodyHtml);
    }

    #[Test]
    public function empty_paragraph_emits_nbsp(): void
    {
        $doc = new Document(section: new Section([
            new Paragraph([]),
        ]));
        $html = (new Serializer)->serialize($doc)->bodyHtml;
        self::assertSame('<p>&nbsp;</p>', $html);
    }

    #[Test]
    public function html_escaping_prevents_injection(): void
    {
        $doc = new Document(section: new Section([
            new Paragraph([new Run('<script>alert("xss")</script>')]),
        ]));
        $html = (new Serializer)->serialize($doc)->bodyHtml;
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    #[Test]
    public function header_and_footer_serialize_separately(): void
    {
        $doc = new Document(section: new Section(
            body: [new Paragraph([new Run('B')])],
            header: [new Paragraph([new Run('H')])],
            footer: [new Paragraph([new Run('F')])],
        ));
        $imported = (new Serializer)->serialize($doc);
        self::assertSame('<p>B</p>', $imported->bodyHtml);
        self::assertSame('<p>H</p>', $imported->headerHtml);
        self::assertSame('<p>F</p>', $imported->footerHtml);
    }
}
