<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Tests\Reader;

use Dskripchenko\PhpDocx\Build\DocumentBuilder;
use Dskripchenko\PhpDocx\Element\Paragraph;
use Dskripchenko\PhpDocx\Reader\DocxReader;
use Dskripchenko\PhpDocx\Style\Orientation;
use Dskripchenko\PhpDocx\Style\PageSetup;
use Dskripchenko\PhpDocx\Style\PaperSize;
use Dskripchenko\PhpDocx\Style\SectionStart;
use Dskripchenko\PhpDocx\Writer\Word2007Writer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Every section is read back with its own body, page setup, start and headers. */
final class SectionsReaderTest extends TestCase
{
    /** @param list<mixed> $blocks */
    private static function texts(array $blocks): array
    {
        return array_map(static fn ($b): string => $b instanceof Paragraph ? implode('', array_map(static fn ($c) => $c->text ?? '', $b->children)) : get_class($b), $blocks);
    }

    #[Test]
    public function sections_survive_a_round_trip(): void
    {
        $bytes = DocumentBuilder::new()
            ->header(fn ($h) => $h->paragraph('Header A'))
            ->paragraph('one')
            ->paragraph('two')
            ->newSection(new PageSetup(orientation: Orientation::Landscape), SectionStart::Continuous)
            ->header(fn ($h) => $h->paragraph('Header B'))
            ->firstHeader(fn ($h) => $h->paragraph('Title B'))
            ->paragraph('three')
            ->newSection(new PageSetup(widthTwips: 9000, heightTwips: 7000))
            ->paragraph('four')
            ->toBytes();

        $doc = (new DocxReader)->read($bytes);
        $sections = $doc->sections();
        self::assertCount(3, $sections);
        [$a, $b, $c] = $sections;

        self::assertSame(['one', 'two'], self::texts($a->body));
        self::assertSame(['three'], self::texts($b->body));
        self::assertSame(['four'], self::texts($c->body));

        self::assertSame(['Header A'], self::texts($a->header));
        self::assertSame(['Header B'], self::texts($b->header));
        self::assertSame(['Title B'], self::texts($b->firstHeader));
        self::assertSame([], $c->header, 'no references of its own: it inherits');

        self::assertSame(PaperSize::A4, $a->pageSetup->paperSize);
        self::assertSame(Orientation::Portrait, $a->pageSetup->orientation);
        self::assertSame(Orientation::Landscape, $b->pageSetup->orientation);
        self::assertSame([9000, 7000], [$c->pageSetup->pageWidthTwips(), $c->pageSetup->pageHeightTwips()]);

        self::assertSame(SectionStart::Continuous, $b->start);
        self::assertSame(SectionStart::NextPage, $c->start);
    }

    #[Test]
    public function writing_what_was_read_gives_the_same_sections(): void
    {
        $bytes = DocumentBuilder::new()->paragraph('one')->newSection(new PageSetup(orientation: Orientation::Landscape))->paragraph('two')->toBytes();
        $again = (new DocxReader)->read((new Word2007Writer)->write((new DocxReader)->read($bytes)));
        self::assertCount(2, $again->sections());
        self::assertSame(['two'], self::texts($again->sections()[1]->body));
        self::assertSame(Orientation::Landscape, $again->sections()[1]->pageSetup->orientation);
    }

    #[Test]
    public function a_one_section_document_reads_as_one(): void
    {
        $doc = (new DocxReader)->read(DocumentBuilder::new()->paragraph('x')->toBytes());
        self::assertCount(1, $doc->sections());
        self::assertSame(['x'], self::texts($doc->section->body));
    }
}
