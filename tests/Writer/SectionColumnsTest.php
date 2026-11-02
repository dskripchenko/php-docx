<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Tests\Writer;

use Dskripchenko\PhpDocx\Document;
use Dskripchenko\PhpDocx\Element\ColumnBreak;
use Dskripchenko\PhpDocx\Element\Paragraph;
use Dskripchenko\PhpDocx\Element\Run;
use Dskripchenko\PhpDocx\Reader\DocxReader;
use Dskripchenko\PhpDocx\Section;
use Dskripchenko\PhpDocx\Style\Columns;
use Dskripchenko\PhpDocx\Writer\Word2007Writer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Section columns: `Section::$columns` ↔ `<w:cols>` in the section's `<w:sectPr>`. */
final class SectionColumnsTest extends TestCase
{
    private static function documentXml(Document $doc): string
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'docx-cols-');
        file_put_contents($tmp, (new Word2007Writer)->write($doc));
        $zip = new \ZipArchive;
        $zip->open($tmp);
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        unlink($tmp);

        return $xml;
    }

    private static function oneSection(?Columns $columns): Document
    {
        return new Document(new Section([new Paragraph([new Run('x')])], columns: $columns));
    }

    #[Test]
    public function a_section_without_columns_writes_no_cols(): void
    {
        self::assertStringNotContainsString('<w:cols', self::documentXml(self::oneSection(null)));
    }

    #[Test]
    public function equal_columns_write_count_and_space(): void
    {
        $xml = self::documentXml(self::oneSection(new Columns(count: 3, spaceTwips: 360)));
        self::assertStringContainsString('<w:cols w:num="3" w:space="360"/>', $xml);
    }

    #[Test]
    public function the_separator_writes_sep(): void
    {
        $xml = self::documentXml(self::oneSection(new Columns(count: 2, separator: true)));
        self::assertStringContainsString('<w:cols w:num="2" w:space="720" w:sep="1"/>', $xml);
    }

    #[Test]
    public function unequal_columns_write_each_width_and_gap(): void
    {
        $columns = new Columns(count: 3, separator: true, widthsTwips: [3000, 4000, 2000], spacesTwips: [500, 700]);
        $xml = self::documentXml(self::oneSection($columns));
        self::assertStringContainsString(
            '<w:cols w:num="3" w:space="720" w:equalWidth="0" w:sep="1">'
            .'<w:col w:w="3000" w:space="500"/><w:col w:w="4000" w:space="700"/><w:col w:w="2000"/>'
            .'</w:cols>',
            $xml,
        );
    }

    #[Test]
    public function unequal_columns_without_gaps_use_the_common_space(): void
    {
        $xml = self::documentXml(self::oneSection(new Columns(count: 2, spaceTwips: 400, widthsTwips: [3000, 6000])));
        self::assertStringContainsString('<w:cols w:num="2" w:space="400" w:equalWidth="0"><w:col w:w="3000" w:space="400"/><w:col w:w="6000"/></w:cols>', $xml);
    }

    #[Test]
    public function cols_sits_after_pgMar_and_before_titlePg(): void
    {
        $section = new Section(
            [new Paragraph([new Run('x')])],
            firstHeader: [new Paragraph([new Run('Title')])],
            columns: new Columns(count: 2),
        );
        $xml = self::documentXml(new Document($section));
        $pgMar = strpos($xml, '<w:pgMar');
        $cols = strpos($xml, '<w:cols');
        $titlePg = strpos($xml, '<w:titlePg');
        self::assertNotFalse($pgMar);
        self::assertNotFalse($cols);
        self::assertNotFalse($titlePg);
        self::assertTrue($pgMar < $cols && $cols < $titlePg);
    }

    #[Test]
    public function every_section_has_its_own_columns(): void
    {
        $doc = new Document(
            new Section([new Paragraph([new Run('one')])], columns: new Columns(count: 2)),
            followingSections: [new Section([new Paragraph([new Run('two')])])],
        );
        $xml = self::documentXml($doc);
        self::assertSame(1, substr_count($xml, '<w:cols'));
        self::assertLessThan(strpos($xml, '</w:p>'), strpos($xml, '<w:cols'), 'rides on the first section\'s sectPr, in the pPr');
    }

    #[Test]
    public function columns_survive_a_round_trip(): void
    {
        foreach ([
            new Columns(count: 3, spaceTwips: 360),
            new Columns(count: 2, spaceTwips: 720, separator: true),
            new Columns(count: 3, separator: true, widthsTwips: [3000, 4000, 2000], spacesTwips: [500, 700]),
            new Columns(count: 2, spaceTwips: 400, widthsTwips: [3000, 6000], spacesTwips: [400]),
        ] as $columns) {
            $bytes = (new Word2007Writer)->write(self::oneSection($columns));
            $read = (new DocxReader)->read($bytes)->section->columns;
            self::assertEquals($columns, $read);
        }
    }

    #[Test]
    public function a_section_without_columns_reads_back_without(): void
    {
        $read = (new DocxReader)->read((new Word2007Writer)->write(self::oneSection(null)));
        self::assertNull($read->section->columns);
    }

    #[Test]
    public function columns_are_read_per_section(): void
    {
        $doc = new Document(
            new Section([new Paragraph([new Run('one')])]),
            followingSections: [new Section([new Paragraph([new Run('two')])], columns: new Columns(count: 2, separator: true))],
        );
        $read = (new DocxReader)->read((new Word2007Writer)->write($doc))->sections();
        self::assertNull($read[0]->columns);
        self::assertEquals(new Columns(count: 2, separator: true), $read[1]->columns);
    }

    #[Test]
    public function invalid_columns_are_refused(): void
    {
        foreach ([
            fn () => new Columns(count: 0),
            fn () => new Columns(count: 2, spaceTwips: -1),
            fn () => new Columns(count: 2, widthsTwips: [3000]),
            fn () => new Columns(count: 2, widthsTwips: [3000, 0]),
            fn () => new Columns(count: 3, widthsTwips: [1, 2, 3], spacesTwips: [100]),
            fn () => new Columns(count: 2, spacesTwips: [100]),
        ] as $make) {
            try {
                $make();
                self::fail('expected InvalidArgumentException');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function a_bare_cols_from_word_is_a_single_column(): void
    {
        $bytes = (new Word2007Writer)->write(self::oneSection(new Columns(count: 2)));
        $tmp = (string) tempnam(sys_get_temp_dir(), 'docx-cols-');
        file_put_contents($tmp, $bytes);
        $zip = new \ZipArchive;
        $zip->open($tmp);
        $xml = str_replace('<w:cols w:num="2" w:space="720"/>', '<w:cols w:space="720"/>', (string) $zip->getFromName('word/document.xml'));
        $zip->addFromString('word/document.xml', $xml);
        $zip->close();

        self::assertStringContainsString('<w:cols w:space="720"/>', $xml);
        self::assertNull((new DocxReader)->read((string) file_get_contents($tmp))->section->columns);
        unlink($tmp);
    }

    #[Test]
    public function a_column_break_survives_a_round_trip(): void
    {
        $doc = new Document(new Section(
            [new Paragraph([new Run('left')]), new ColumnBreak, new Paragraph([new Run('right')])],
            columns: new Columns(count: 2),
        ));
        $read = (new DocxReader)->read((new Word2007Writer)->write($doc))->section;
        self::assertCount(3, $read->body);
        self::assertInstanceOf(ColumnBreak::class, $read->body[1]);
        self::assertEquals(new Columns(count: 2), $read->columns);
    }
}
