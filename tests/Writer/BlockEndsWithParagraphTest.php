<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Tests\Writer;

use Dskripchenko\PhpDocx\Document;
use Dskripchenko\PhpDocx\Element\Paragraph;
use Dskripchenko\PhpDocx\Element\Run;
use Dskripchenko\PhpDocx\Element\Table;
use Dskripchenko\PhpDocx\Element\TableCell;
use Dskripchenko\PhpDocx\Element\TableRow;
use Dskripchenko\PhpDocx\Section;
use Dskripchenko\PhpDocx\Writer\Word2007Writer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * A cell and the document body have to end with a paragraph.
 *
 * Word opens such a file offering to «восстановить содержимое» when the last
 * block is a table. Checking whether there is any paragraph inside is not
 * enough: a nested table has paragraphs of its own while the cell still ends on
 * `</w:tbl>` — which is exactly how the code plate printed, the one whose
 * shrink-to-content width is done with a nested table.
 */
final class BlockEndsWithParagraphTest extends TestCase
{
    private function documentXml(Document $doc): string
    {
        $path = tempnam(sys_get_temp_dir(), 'docx-').'.docx';
        file_put_contents($path, (new Word2007Writer)->write($doc));

        $zip = new ZipArchive;
        $zip->open($path);
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        @unlink($path);

        return $xml;
    }

    private function nestedTableCell(): TableCell
    {
        $inner = new Table([new TableRow([new TableCell([new Paragraph([new Run('код')])])])]);

        return new TableCell([$inner]);
    }

    #[Test]
    public function a_cell_holding_only_a_nested_table_gets_a_closing_paragraph(): void
    {
        $doc = new Document(new Section(body: [
            new Table([new TableRow([$this->nestedTableCell()])]),
            new Paragraph([new Run('после')]),
        ]));

        $xml = $this->documentXml($doc);

        self::assertStringContainsString('</w:tbl><w:p/></w:tc>', $xml, 'ячейка заканчивается таблицей');
    }

    #[Test]
    public function a_document_ending_with_a_table_gets_a_closing_paragraph(): void
    {
        $doc = new Document(new Section(body: [
            new Table([new TableRow([new TableCell([new Paragraph([new Run('строка')])])])]),
        ]));

        $xml = $this->documentXml($doc);

        self::assertStringContainsString('</w:tbl><w:p/><w:sectPr>', $xml, 'тело заканчивается таблицей');
    }

    #[Test]
    public function a_document_that_already_ends_with_a_paragraph_gets_nothing_extra(): void
    {
        $doc = new Document(new Section(body: [new Paragraph([new Run('конец')])]));

        self::assertStringNotContainsString('</w:p><w:p/><w:sectPr>', $this->documentXml($doc));
    }
}
