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
use Dskripchenko\PhpDocx\Style\Orientation;
use Dskripchenko\PhpDocx\Style\PageSetup;
use Dskripchenko\PhpDocx\Style\SectionStart;
use Dskripchenko\PhpDocx\Writer\Word2007Writer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Several sections in one document: each ends with its own `<w:sectPr>` (in
 * the pPr of its last paragraph, the last section's directly in the body),
 * with its own page setup, start type and header/footer references.
 */
final class SectionsTest extends TestCase
{
    /** @return array<string, string> entry name → contents */
    private static function parts(string $bytes): array
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'docx-sect-');
        file_put_contents($tmp, $bytes);
        $zip = new \ZipArchive;
        $zip->open($tmp);
        $out = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $out[$name] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        unlink($tmp);

        return $out;
    }

    private static function xpath(string $xml): \DOMXPath
    {
        $dom = new \DOMDocument;
        $dom->loadXML($xml);
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $xp->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

        return $xp;
    }

    private static function p(string $text): Paragraph
    {
        return new Paragraph([new Run($text)]);
    }

    #[Test]
    public function a_custom_page_size_is_written_as_given(): void
    {
        $setup = new PageSetup(widthTwips: 8000, heightTwips: 12000);
        self::assertSame(8000 - 850 - 850, $setup->contentWidthTwips());

        $xp = self::xpath(self::parts((new Word2007Writer)->write(new Document(new Section([self::p('x')], pageSetup: $setup))))['word/document.xml']);
        self::assertSame('8000', $xp->evaluate('string(//w:body/w:sectPr/w:pgSz/@w:w)'));
        self::assertSame('12000', $xp->evaluate('string(//w:body/w:sectPr/w:pgSz/@w:h)'));
        self::assertSame('portrait', $xp->evaluate('string(//w:body/w:sectPr/w:pgSz/@w:orient)'));
    }

    #[Test]
    public function a_wider_custom_page_is_landscape(): void
    {
        $xp = self::xpath(self::parts((new Word2007Writer)->write(new Document(new Section([self::p('x')], pageSetup: new PageSetup(widthTwips: 12000, heightTwips: 8000)))))['word/document.xml']);
        self::assertSame('landscape', $xp->evaluate('string(//w:body/w:sectPr/w:pgSz/@w:orient)'));
        self::assertSame('12000', $xp->evaluate('string(//w:body/w:sectPr/w:pgSz/@w:w)'));
    }

    #[Test]
    public function each_section_ends_with_its_own_settings(): void
    {
        $doc = new Document(
            new Section([self::p('one'), self::p('two')], header: [self::p('Header A')]),
            followingSections: [
                new Section([self::p('three')], header: [self::p('Header B')], pageSetup: new PageSetup(orientation: Orientation::Landscape), start: SectionStart::Continuous),
            ],
        );
        self::assertCount(2, $doc->sections());
        $parts = self::parts((new Word2007Writer)->write($doc));
        $xp = self::xpath($parts['word/document.xml']);

        // The first section's sectPr rides on its last paragraph: no extra paragraph.
        self::assertSame(3.0, $xp->evaluate('count(//w:body/w:p)'));
        self::assertSame('two', $xp->evaluate('string(//w:body/w:p[w:pPr/w:sectPr]//w:t)'));
        self::assertSame('portrait', $xp->evaluate('string(//w:p/w:pPr/w:sectPr/w:pgSz/@w:orient)'));
        self::assertSame('landscape', $xp->evaluate('string(//w:body/w:sectPr/w:pgSz/@w:orient)'));
        self::assertSame('continuous', $xp->evaluate('string(//w:body/w:sectPr/w:type/@w:val)'));
        self::assertSame(0.0, $xp->evaluate('count(//w:p/w:pPr/w:sectPr/w:type)'), 'next page is the default and needs no w:type');

        // Each section points at its own header part.
        $first = $xp->evaluate('string(//w:p/w:pPr/w:sectPr/w:headerReference/@r:id)');
        $last = $xp->evaluate('string(//w:body/w:sectPr/w:headerReference/@r:id)');
        self::assertNotSame('', $first);
        self::assertNotSame($first, $last);
        self::assertStringContainsString('Header A', $parts['word/header1.xml']);
        self::assertStringContainsString('Header B', $parts['word/header2.xml']);
    }

    #[Test]
    public function a_section_without_headers_writes_no_references_so_it_inherits(): void
    {
        $doc = new Document(
            new Section([self::p('one')], header: [self::p('Header A')]),
            followingSections: [new Section([self::p('two')])],
        );
        $xp = self::xpath(self::parts((new Word2007Writer)->write($doc))['word/document.xml']);
        self::assertSame(1.0, $xp->evaluate('count(//w:p/w:pPr/w:sectPr/w:headerReference)'));
        self::assertSame(0.0, $xp->evaluate('count(//w:body/w:sectPr/w:headerReference)'));
    }

    #[Test]
    public function a_section_ending_in_a_table_gets_a_paragraph_for_its_settings(): void
    {
        $table = new Table([new TableRow([new TableCell([self::p('cell')])])]);
        $doc = new Document(new Section([self::p('one'), $table]), followingSections: [new Section([self::p('two')])]);
        $xp = self::xpath(self::parts((new Word2007Writer)->write($doc))['word/document.xml']);
        self::assertSame(1.0, $xp->evaluate('count(//w:body/w:tbl/following-sibling::w:p[1]/w:pPr/w:sectPr)'));
    }

    #[Test]
    public function first_page_headers_are_per_section(): void
    {
        $doc = new Document(
            new Section([self::p('one')]),
            followingSections: [new Section([self::p('two')], firstHeader: [self::p('Title')])],
        );
        $xp = self::xpath(self::parts((new Word2007Writer)->write($doc))['word/document.xml']);
        self::assertSame(0.0, $xp->evaluate('count(//w:p/w:pPr/w:sectPr/w:titlePg)'));
        self::assertSame(1.0, $xp->evaluate('count(//w:body/w:sectPr/w:titlePg)'));
    }

    #[Test]
    public function a_one_section_document_is_unchanged(): void
    {
        $doc = new Document(new Section([self::p('x')]));
        self::assertSame([$doc->section], $doc->sections());
        $xp = self::xpath(self::parts((new Word2007Writer)->write($doc))['word/document.xml']);
        self::assertSame(1.0, $xp->evaluate('count(//w:sectPr)'));
    }
}
