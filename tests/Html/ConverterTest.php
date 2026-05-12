<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Tests\Html;

use Dskripchenko\PhpDocx\Element\HorizontalRule;
use Dskripchenko\PhpDocx\Element\Hyperlink;
use Dskripchenko\PhpDocx\Element\LineBreak;
use Dskripchenko\PhpDocx\Element\PageBreak;
use Dskripchenko\PhpDocx\Element\Paragraph;
use Dskripchenko\PhpDocx\Element\Run;
use Dskripchenko\PhpDocx\Element\Table;
use Dskripchenko\PhpDocx\Html\Converter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConverterTest extends TestCase
{
    #[Test]
    public function it_returns_empty_section_for_empty_input(): void
    {
        $doc = (new Converter)->fromHtml('');
        self::assertSame([], $doc->section->body);
    }

    #[Test]
    public function it_wraps_root_text_in_paragraph(): void
    {
        $doc = (new Converter)->fromHtml('Hello world');
        self::assertCount(1, $doc->section->body);

        $p = $doc->section->body[0];
        self::assertInstanceOf(Paragraph::class, $p);
        self::assertCount(1, $p->children);
        self::assertInstanceOf(Run::class, $p->children[0]);
        self::assertSame('Hello world', $p->children[0]->text);
    }

    #[Test]
    public function it_parses_paragraph(): void
    {
        $doc = (new Converter)->fromHtml('<p>First</p><p>Second</p>');
        self::assertCount(2, $doc->section->body);

        self::assertInstanceOf(Paragraph::class, $doc->section->body[0]);
        self::assertInstanceOf(Paragraph::class, $doc->section->body[1]);
        self::assertSame('First', $doc->section->body[0]->children[0]->text);
        self::assertSame('Second', $doc->section->body[1]->children[0]->text);
    }

    #[Test]
    public function it_parses_headings_with_levels(): void
    {
        $doc = (new Converter)->fromHtml('<h1>Title</h1><h3>Subtitle</h3>');
        self::assertSame(1, $doc->section->body[0]->headingLevel);
        self::assertSame(3, $doc->section->body[1]->headingLevel);
    }

    #[Test]
    public function it_applies_strong_em_u_marks(): void
    {
        $doc = (new Converter)->fromHtml('<p><strong>bold</strong> <em>italic</em> <u>under</u></p>');
        $children = $doc->section->body[0]->children;

        self::assertGreaterThanOrEqual(3, count($children));
        self::assertTrue($this->findRunWithText($children, 'bold')->style->bold);
        self::assertTrue($this->findRunWithText($children, 'italic')->style->italic);
        self::assertTrue($this->findRunWithText($children, 'under')->style->underline);
    }

    #[Test]
    public function it_parses_inline_style_color_and_size(): void
    {
        $doc = (new Converter)->fromHtml('<p><span style="color: #14b8a6; font-size: 16pt">teal</span></p>');
        $run = $doc->section->body[0]->children[0];

        self::assertSame('14b8a6', $run->style->color);
        self::assertSame(32, $run->style->sizeHalfPoints);
    }

    #[Test]
    public function it_parses_br_as_line_break(): void
    {
        $doc = (new Converter)->fromHtml('<p>A<br>B</p>');
        $children = $doc->section->body[0]->children;

        self::assertInstanceOf(Run::class, $children[0]);
        self::assertInstanceOf(LineBreak::class, $children[1]);
        self::assertInstanceOf(Run::class, $children[2]);
    }

    #[Test]
    public function it_parses_hr(): void
    {
        $doc = (new Converter)->fromHtml('<hr>');
        self::assertInstanceOf(HorizontalRule::class, $doc->section->body[0]);
    }

    #[Test]
    public function it_parses_page_break_div_class(): void
    {
        $doc = (new Converter)->fromHtml('<hr class="page-break">');
        self::assertInstanceOf(PageBreak::class, $doc->section->body[0]);
    }

    #[Test]
    public function it_parses_anchor_as_hyperlink(): void
    {
        $doc = (new Converter)->fromHtml('<p>See <a href="https://example.com">site</a>.</p>');
        $children = $doc->section->body[0]->children;

        $hl = null;
        foreach ($children as $c) {
            if ($c instanceof Hyperlink) {
                $hl = $c;
            }
        }
        self::assertNotNull($hl);
        self::assertSame('https://example.com', $hl->href);
        self::assertSame('site', $hl->children[0]->text);
    }

    #[Test]
    public function it_parses_simple_table(): void
    {
        $html = '<table><tr><td>A</td><td>B</td></tr><tr><td>C</td><td>D</td></tr></table>';
        $doc = (new Converter)->fromHtml($html);

        self::assertInstanceOf(Table::class, $doc->section->body[0]);
        /** @var Table $t */
        $t = $doc->section->body[0];
        self::assertCount(2, $t->rows);
        self::assertCount(2, $t->rows[0]->cells);
        self::assertSame('A', $t->rows[0]->cells[0]->children[0]->children[0]->text);
        self::assertSame('D', $t->rows[1]->cells[1]->children[0]->children[0]->text);
    }

    #[Test]
    public function it_parses_thead_header_rows(): void
    {
        $html = '<table><thead><tr><th>H</th></tr></thead><tbody><tr><td>B</td></tr></tbody></table>';
        $doc = (new Converter)->fromHtml($html);
        /** @var Table $t */
        $t = $doc->section->body[0];

        self::assertTrue($t->rows[0]->isHeader);
        self::assertFalse($t->rows[1]->isHeader);
        // th's по дефолту bold
        self::assertTrue($t->rows[0]->cells[0]->children[0]->children[0]->style->bold);
    }

    #[Test]
    public function it_parses_cell_width_percent(): void
    {
        $html = '<table><tr><td style="width: 36%">L</td><td>R</td></tr></table>';
        $doc = (new Converter)->fromHtml($html);
        /** @var Table $t */
        $t = $doc->section->body[0];

        // 36% × 50 = 1800
        self::assertSame(1800, $t->rows[0]->cells[0]->style->widthPercent);
    }

    #[Test]
    public function it_parses_data_url_image(): void
    {
        // 1×1 transparent PNG
        $pngBin = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
        $b64 = base64_encode($pngBin);
        $html = '<p><img src="data:image/png;base64,'.$b64.'" width="100" height="50" alt="X"></p>';

        $doc = (new Converter)->fromHtml($html);
        $children = $doc->section->body[0]->children;
        $img = $children[0];

        self::assertInstanceOf(\Dskripchenko\PhpDocx\Element\Image::class, $img);
        self::assertSame(100 * 9525, $img->widthEmu);
        self::assertSame(50 * 9525, $img->heightEmu);
        self::assertSame('X', $img->altText);
        self::assertSame($pngBin, $img->binary);
    }

    #[Test]
    public function it_handles_empty_cells_gracefully(): void
    {
        $doc = (new Converter)->fromHtml('<table><tr><td></td><td>x</td></tr></table>');
        /** @var Table $t */
        $t = $doc->section->body[0];

        // Пустая ячейка должна иметь как минимум один (пустой) paragraph.
        self::assertCount(1, $t->rows[0]->cells[0]->children);
        self::assertInstanceOf(Paragraph::class, $t->rows[0]->cells[0]->children[0]);
    }

    #[Test]
    public function it_collapses_whitespace_in_runs(): void
    {
        $doc = (new Converter)->fromHtml("<p>Hello   \n  world</p>");
        self::assertSame('Hello world', $doc->section->body[0]->children[0]->text);
    }

    /**
     * @param  list<mixed>  $children
     */
    private function findRunWithText(array $children, string $text): Run
    {
        foreach ($children as $c) {
            if ($c instanceof Run && trim($c->text) === $text) {
                return $c;
            }
        }
        self::fail("Run with text '{$text}' not found");
    }
}
