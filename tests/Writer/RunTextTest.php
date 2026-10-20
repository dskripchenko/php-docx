<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Tests\Writer;

use Dskripchenko\PhpDocx\Document;
use Dskripchenko\PhpDocx\Element\Hyperlink;
use Dskripchenko\PhpDocx\Element\LineBreak;
use Dskripchenko\PhpDocx\Element\Paragraph;
use Dskripchenko\PhpDocx\Element\Run;
use Dskripchenko\PhpDocx\Element\Table;
use Dskripchenko\PhpDocx\Element\TableCell;
use Dskripchenko\PhpDocx\Element\TableRow;
use Dskripchenko\PhpDocx\Html\Converter;
use Dskripchenko\PhpDocx\Reader\DocxReader;
use Dskripchenko\PhpDocx\Section;
use Dskripchenko\PhpDocx\Style\CellStyle;
use Dskripchenko\PhpDocx\Style\RunStyle;
use Dskripchenko\PhpDocx\Style\TableStyle;
use Dskripchenko\PhpDocx\Writer\BodyXmlBuilder;
use Dskripchenko\PhpDocx\Writer\Word2007Writer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A tab or a newline inside `<w:t>` is not a tab or a line break to Word:
 * run text is split into `<w:t>`, `<w:tab/>` and `<w:br/>`.
 */
final class RunTextTest extends TestCase
{
    #[Test]
    public function a_tab_becomes_w_tab(): void
    {
        $xml = (new BodyXmlBuilder)->render([new Paragraph([new Run("Name:\tValue")])]);

        self::assertStringContainsString(
            '<w:r><w:t xml:space="preserve">Name:</w:t><w:tab/><w:t xml:space="preserve">Value</w:t></w:r>',
            $xml,
        );
        self::assertStringNotContainsString("\t", $xml);
    }

    #[Test]
    public function every_newline_form_becomes_w_br(): void
    {
        $xml = (new BodyXmlBuilder)->render([new Paragraph([new Run("a\nb\r\nc\rd")])]);

        self::assertStringContainsString(
            '<w:t xml:space="preserve">a</w:t><w:br/>'
            .'<w:t xml:space="preserve">b</w:t><w:br/>'
            .'<w:t xml:space="preserve">c</w:t><w:br/>'
            .'<w:t xml:space="preserve">d</w:t>',
            $xml,
        );
        self::assertStringNotContainsString("\n", $xml);
        self::assertStringNotContainsString("\r", $xml);
    }

    #[Test]
    public function no_empty_w_t_around_tabs(): void
    {
        $xml = (new BodyXmlBuilder)->render([new Paragraph([new Run("\t\tx\t")])]);

        self::assertStringContainsString(
            '<w:r><w:tab/><w:tab/><w:t xml:space="preserve">x</w:t><w:tab/></w:r>',
            $xml,
        );
        self::assertStringNotContainsString('<w:t xml:space="preserve"></w:t>', $xml);
    }

    #[Test]
    public function the_run_properties_stay_first(): void
    {
        $xml = (new BodyXmlBuilder)->render([new Paragraph([new Run("a\tb", new RunStyle(bold: true))])]);

        self::assertMatchesRegularExpression('#<w:r><w:rPr>.*?<w:b/>.*?</w:rPr><w:t[^>]*>a</w:t><w:tab/>#', $xml);
    }

    #[Test]
    public function plain_and_empty_runs_are_unchanged(): void
    {
        $xml = (new BodyXmlBuilder)->render([new Paragraph([new Run('plain'), new Run('')])]);

        self::assertStringContainsString('<w:r><w:t xml:space="preserve">plain</w:t></w:r>', $xml);
        self::assertStringContainsString('<w:r><w:t xml:space="preserve"></w:t></w:r>', $xml);
    }

    #[Test]
    public function hyperlink_text_and_table_caption_are_split_too(): void
    {
        $xml = (new BodyXmlBuilder)->render([
            new Paragraph([Hyperlink::internal('x', [new Run("a\tb")])]),
            new Table(
                [new TableRow([new TableCell([new Paragraph([new Run('c')])], new CellStyle)])],
                new TableStyle,
                "Table 1\tTotals",
            ),
        ]);

        self::assertStringContainsString('<w:hyperlink w:anchor="x"><w:r><w:t xml:space="preserve">a</w:t><w:tab/>', $xml);
        self::assertStringContainsString('<w:t xml:space="preserve">Table 1</w:t><w:tab/><w:t xml:space="preserve">Totals</w:t>', $xml);
    }

    #[Test]
    public function header_text_is_split_too(): void
    {
        $bytes = (new Word2007Writer)->write(new Document(new Section(
            body: [new Paragraph([new Run('body')])],
            header: [new Paragraph([new Run("left\tright")])],
        )));

        $header = self::part($bytes, 'word/header1.xml');
        self::assertStringContainsString('<w:t xml:space="preserve">left</w:t><w:tab/><w:t xml:space="preserve">right</w:t>', $header);
    }

    #[Test]
    public function tabs_and_line_breaks_survive_a_read_write_round_trip(): void
    {
        $source = (new Word2007Writer)->write(new Document(new Section([
            new Paragraph([new Run("Name:\tValue"), new LineBreak, new Run('next')]),
        ])));

        // The reader gives the tab back as "\t" in the run text.
        $read = (new DocxReader)->read($source);
        $bytes = (new Word2007Writer)->write(new Document($read->section));

        $xml = self::part($bytes, 'word/document.xml');
        self::assertStringContainsString('<w:t xml:space="preserve">Name:</w:t><w:tab/><w:t xml:space="preserve">Value</w:t>', $xml);
        self::assertStringContainsString('<w:br/>', $xml);
        self::assertStringNotContainsString("\t", $xml);
    }

    #[Test]
    public function newlines_in_html_pre_become_line_breaks(): void
    {
        // The converter keeps the newlines of a <pre> in the run text.
        $doc = (new Converter)->fromHtml("<pre>line 1\nline 2</pre>");
        $xml = self::part((new Word2007Writer)->write($doc), 'word/document.xml');

        self::assertStringContainsString('<w:t xml:space="preserve">line 1</w:t><w:br/><w:t xml:space="preserve">line 2</w:t>', $xml);
    }

    private static function part(string $bytes, string $name): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'docx-test-');
        self::assertIsString($tmp);
        file_put_contents($tmp, $bytes);
        $zip = new \ZipArchive;
        $zip->open($tmp);
        $xml = $zip->getFromName($name);
        $zip->close();
        unlink($tmp);
        self::assertIsString($xml);

        return $xml;
    }
}
