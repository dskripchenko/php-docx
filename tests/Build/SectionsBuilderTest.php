<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Tests\Build;

use Dskripchenko\PhpDocx\Build\DocumentBuilder;
use Dskripchenko\PhpDocx\Element\Paragraph;
use Dskripchenko\PhpDocx\Style\Orientation;
use Dskripchenko\PhpDocx\Style\PageSetup;
use Dskripchenko\PhpDocx\Style\SectionStart;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SectionsBuilderTest extends TestCase
{
    private static function text(mixed $block): string
    {
        self::assertInstanceOf(Paragraph::class, $block);

        return $block->children[0]->text;
    }

    #[Test]
    public function new_section_closes_the_current_one(): void
    {
        $landscape = new PageSetup(orientation: Orientation::Landscape);
        $doc = DocumentBuilder::new()
            ->header(fn ($h) => $h->paragraph('Header A'))
            ->paragraph('one')
            ->newSection($landscape, SectionStart::OddPage)
            ->footer(fn ($f) => $f->paragraph('Footer B'))
            ->paragraph('two')
            ->build();

        [$a, $b] = $doc->sections();
        self::assertSame('one', self::text($a->body[0]));
        self::assertSame('Header A', self::text($a->header[0]));
        self::assertSame([], $a->footer);
        self::assertSame('two', self::text($b->body[0]));
        self::assertSame([], $b->header, 'a new section inherits the header unless it sets one');
        self::assertSame('Footer B', self::text($b->footer[0]));
        self::assertSame($landscape, $b->pageSetup);
        self::assertSame(SectionStart::OddPage, $b->start);
    }

    #[Test]
    public function a_new_section_keeps_the_page_setup_unless_given_one(): void
    {
        $setup = new PageSetup(widthTwips: 9000, heightTwips: 9000);
        $doc = DocumentBuilder::new()->pageSetup($setup)->paragraph('one')->newSection()->paragraph('two')->build();
        self::assertSame($setup, $doc->sections()[1]->pageSetup);
        self::assertSame(SectionStart::NextPage, $doc->sections()[1]->start);
    }

    #[Test]
    public function without_new_section_there_is_one(): void
    {
        self::assertCount(1, DocumentBuilder::new()->paragraph('x')->build()->sections());
    }
}
