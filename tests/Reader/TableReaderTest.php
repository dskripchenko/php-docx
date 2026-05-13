<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Tests\Reader;

use Dskripchenko\PhpDocx\Element\Paragraph;
use Dskripchenko\PhpDocx\Element\Run;
use Dskripchenko\PhpDocx\Element\Table;
use Dskripchenko\PhpDocx\Html\Converter;
use Dskripchenko\PhpDocx\Reader\BodyReader;
use Dskripchenko\PhpDocx\Reader\DocxPackageReader;
use Dskripchenko\PhpDocx\Reader\OoxmlNs;
use Dskripchenko\PhpDocx\Reader\StylesResolver;
use Dskripchenko\PhpDocx\Reader\TableReader;
use Dskripchenko\PhpDocx\Writer\Word2007Writer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TableReaderTest extends TestCase
{
    #[Test]
    public function basic_2x2_table(): void
    {
        $tbl = $this->loadTable(
            '<w:tbl>'
            .'<w:tr><w:tc><w:p><w:r><w:t>A</w:t></w:r></w:p></w:tc>'
            .'<w:tc><w:p><w:r><w:t>B</w:t></w:r></w:p></w:tc></w:tr>'
            .'<w:tr><w:tc><w:p><w:r><w:t>C</w:t></w:r></w:p></w:tc>'
            .'<w:tc><w:p><w:r><w:t>D</w:t></w:r></w:p></w:tc></w:tr>'
            .'</w:tbl>'
        );
        $t = (new TableReader)->read($tbl);

        self::assertInstanceOf(Table::class, $t);
        self::assertCount(2, $t->rows);
        self::assertCount(2, $t->rows[0]->cells);
        self::assertSame('A', $this->cellText($t, 0, 0));
        self::assertSame('D', $this->cellText($t, 1, 1));
    }

    #[Test]
    public function gridSpan_becomes_colspan(): void
    {
        $tbl = $this->loadTable(
            '<w:tbl>'
            .'<w:tr>'
            .'<w:tc><w:tcPr><w:gridSpan w:val="2"/></w:tcPr><w:p><w:r><w:t>Wide</w:t></w:r></w:p></w:tc>'
            .'<w:tc><w:p><w:r><w:t>X</w:t></w:r></w:p></w:tc>'
            .'</w:tr>'
            .'</w:tbl>'
        );
        $t = (new TableReader)->read($tbl);
        self::assertSame(2, $t->rows[0]->cells[0]->style->gridSpan);
        self::assertSame(1, $t->rows[0]->cells[1]->style->gridSpan);
    }

    #[Test]
    public function vMerge_restart_plus_continue_yields_rowSpan_2(): void
    {
        $tbl = $this->loadTable(
            '<w:tbl>'
            .'<w:tr><w:tc><w:tcPr><w:vMerge w:val="restart"/></w:tcPr>'
            .'<w:p><w:r><w:t>M</w:t></w:r></w:p></w:tc>'
            .'<w:tc><w:p><w:r><w:t>R1B</w:t></w:r></w:p></w:tc></w:tr>'
            .'<w:tr><w:tc><w:tcPr><w:vMerge/></w:tcPr>'
            .'<w:p/></w:tc>'
            .'<w:tc><w:p><w:r><w:t>R2B</w:t></w:r></w:p></w:tc></w:tr>'
            .'</w:tbl>'
        );
        $t = (new TableReader)->read($tbl);

        self::assertSame(2, $t->rows[0]->cells[0]->style->rowSpan, 'restart-cell rowSpan=2');
        self::assertTrue($t->rows[1]->cells[0]->style->vMergeContinue);
    }

    #[Test]
    public function vMerge_3_levels(): void
    {
        $tbl = $this->loadTable(
            '<w:tbl>'
            .'<w:tr><w:tc><w:tcPr><w:vMerge w:val="restart"/></w:tcPr><w:p><w:r><w:t>X</w:t></w:r></w:p></w:tc></w:tr>'
            .'<w:tr><w:tc><w:tcPr><w:vMerge/></w:tcPr><w:p/></w:tc></w:tr>'
            .'<w:tr><w:tc><w:tcPr><w:vMerge/></w:tcPr><w:p/></w:tc></w:tr>'
            .'</w:tbl>'
        );
        $t = (new TableReader)->read($tbl);
        self::assertSame(3, $t->rows[0]->cells[0]->style->rowSpan);
    }

    #[Test]
    public function tblGrid_yields_gridColumnsTwips(): void
    {
        $tbl = $this->loadTable(
            '<w:tbl>'
            .'<w:tblGrid><w:gridCol w:w="1500"/><w:gridCol w:w="3000"/></w:tblGrid>'
            .'<w:tr><w:tc><w:p/></w:tc><w:tc><w:p/></w:tc></w:tr>'
            .'</w:tbl>'
        );
        $t = (new TableReader)->read($tbl);
        self::assertSame([1500, 3000], $t->gridColumnsTwips);
    }

    #[Test]
    public function cell_padding_from_tcMar(): void
    {
        $tbl = $this->loadTable(
            '<w:tbl>'
            .'<w:tr><w:tc><w:tcPr>'
            .'<w:tcMar>'
            .'<w:top w:w="120" w:type="dxa"/>'
            .'<w:left w:w="100" w:type="dxa"/>'
            .'<w:bottom w:w="120" w:type="dxa"/>'
            .'<w:right w:w="100" w:type="dxa"/>'
            .'</w:tcMar>'
            .'</w:tcPr><w:p><w:r><w:t>x</w:t></w:r></w:p></w:tc></w:tr>'
            .'</w:tbl>'
        );
        $t = (new TableReader)->read($tbl);
        $s = $t->rows[0]->cells[0]->style;
        self::assertSame(120, $s->paddingTopTwips);
        self::assertSame(100, $s->paddingLeftTwips);
    }

    #[Test]
    public function cell_shading_yields_backgroundColor(): void
    {
        $tbl = $this->loadTable(
            '<w:tbl>'
            .'<w:tr><w:tc>'
            .'<w:tcPr><w:shd w:val="clear" w:color="auto" w:fill="14B8A6"/></w:tcPr>'
            .'<w:p><w:r><w:t>x</w:t></w:r></w:p></w:tc></w:tr>'
            .'</w:tbl>'
        );
        $t = (new TableReader)->read($tbl);
        self::assertSame('14b8a6', $t->rows[0]->cells[0]->style->backgroundColor);
    }

    #[Test]
    public function row_with_tblHeader_marked_header(): void
    {
        $tbl = $this->loadTable(
            '<w:tbl>'
            .'<w:tr><w:trPr><w:tblHeader/></w:trPr>'
            .'<w:tc><w:p><w:r><w:t>H</w:t></w:r></w:p></w:tc></w:tr>'
            .'</w:tbl>'
        );
        $t = (new TableReader)->read($tbl);
        self::assertTrue($t->rows[0]->isHeader);
    }

    #[Test]
    public function trHeight_extracted(): void
    {
        $tbl = $this->loadTable(
            '<w:tbl>'
            .'<w:tr><w:trPr><w:trHeight w:val="800"/></w:trPr>'
            .'<w:tc><w:p><w:r><w:t>x</w:t></w:r></w:p></w:tc></w:tr>'
            .'</w:tbl>'
        );
        $t = (new TableReader)->read($tbl);
        self::assertSame(800, $t->rows[0]->heightTwips);
    }

    #[Test]
    public function nested_table_inside_cell(): void
    {
        $tbl = $this->loadTable(
            '<w:tbl>'
            .'<w:tr><w:tc>'
            .'<w:p/>'
            .'<w:tbl>'
            .'<w:tr><w:tc><w:p><w:r><w:t>nested</w:t></w:r></w:p></w:tc></w:tr>'
            .'</w:tbl>'
            .'</w:tc></w:tr>'
            .'</w:tbl>'
        );
        $t = (new TableReader)->read($tbl);
        $cell = $t->rows[0]->cells[0];
        $hasNestedTable = false;
        foreach ($cell->children as $c) {
            if ($c instanceof Table) {
                $hasNestedTable = true;
            }
        }
        self::assertTrue($hasNestedTable);
    }

    #[Test]
    public function empty_cell_has_one_empty_paragraph(): void
    {
        $tbl = $this->loadTable(
            '<w:tbl><w:tr><w:tc/></w:tr></w:tbl>'
        );
        $t = (new TableReader)->read($tbl);
        $cell = $t->rows[0]->cells[0];
        self::assertCount(1, $cell->children);
        self::assertInstanceOf(Paragraph::class, $cell->children[0]);
    }

    #[Test]
    public function roundtrip_writer_then_reader_preserves_table(): void
    {
        $bytes = (new Word2007Writer)->write((new Converter)->fromHtml(
            '<table>'
            .'<tr><th>A</th><th>B</th></tr>'
            .'<tr><td>1</td><td>2</td></tr>'
            .'</table>'
        ));

        $pkg = (new DocxPackageReader)->read($bytes);
        $resolver = new StylesResolver($pkg->stylesXml);
        $reader = new BodyReader($resolver);
        $body = $pkg->documentXml->getElementsByTagNameNS(OoxmlNs::W, 'body')->item(0);
        self::assertInstanceOf(\DOMElement::class, $body);

        $blocks = $reader->read($body);
        $tables = array_values(array_filter($blocks, fn ($b) => $b instanceof Table));
        self::assertCount(1, $tables);
        /** @var Table $t */
        $t = $tables[0];
        self::assertCount(2, $t->rows);
        self::assertCount(2, $t->rows[0]->cells);
        self::assertSame('A', $this->cellText($t, 0, 0));
        self::assertSame('2', $this->cellText($t, 1, 1));
    }

    #[Test]
    public function rowspan_roundtrip(): void
    {
        $bytes = (new Word2007Writer)->write((new Converter)->fromHtml(
            '<table>'
            .'<tr><td rowspan="2">M</td><td>R1B</td></tr>'
            .'<tr><td>R2B</td></tr>'
            .'</table>'
        ));
        $pkg = (new DocxPackageReader)->read($bytes);
        $resolver = new StylesResolver($pkg->stylesXml);
        $reader = new BodyReader($resolver);
        $body = $pkg->documentXml->getElementsByTagNameNS(OoxmlNs::W, 'body')->item(0);
        self::assertInstanceOf(\DOMElement::class, $body);
        /** @var Table $t */
        $t = $reader->read($body)[0];

        self::assertSame(2, $t->rows[0]->cells[0]->style->rowSpan);
        self::assertTrue($t->rows[1]->cells[0]->style->vMergeContinue);
    }

    private function loadTable(string $tblXml): \DOMElement
    {
        $doc = new \DOMDocument;
        $doc->loadXML(
            '<w:document xmlns:w="'.OoxmlNs::W.'">'
            .'<w:body>'.$tblXml.'</w:body>'
            .'</w:document>'
        );
        $tbl = $doc->getElementsByTagNameNS(OoxmlNs::W, 'tbl')->item(0);
        if (! $tbl instanceof \DOMElement) {
            throw new \RuntimeException('No <w:tbl> in fixture');
        }

        return $tbl;
    }

    private function cellText(Table $t, int $row, int $col): string
    {
        $cell = $t->rows[$row]->cells[$col];
        $text = '';
        foreach ($cell->children as $b) {
            if (! $b instanceof Paragraph) {
                continue;
            }
            foreach ($b->children as $c) {
                if ($c instanceof Run) {
                    $text .= $c->text;
                }
            }
        }

        return $text;
    }
}
