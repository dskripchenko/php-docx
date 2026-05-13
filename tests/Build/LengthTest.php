<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Tests\Build;

use Dskripchenko\PhpDocx\Build\DocumentBuilder;
use Dskripchenko\PhpDocx\Build\Length;
use Dskripchenko\PhpDocx\Build\ParagraphBuilder;
use Dskripchenko\PhpDocx\Build\TableBuilder;
use Dskripchenko\PhpDocx\Build\TableCellBuilder;
use Dskripchenko\PhpDocx\Build\TableRowBuilder;
use Dskripchenko\PhpDocx\Element\Table;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LengthTest extends TestCase
{
    #[Test]
    public function twips_identity(): void
    {
        self::assertSame(720, Length::twips(720));
    }

    #[Test]
    public function pt_to_twips(): void
    {
        // 1pt = 20 twips
        self::assertSame(20, Length::pt(1));
        self::assertSame(200, Length::pt(10));
        // Половина пункта округляется к ближайшему twip
        self::assertSame(10, Length::pt(0.5));
    }

    #[Test]
    public function inch_to_twips(): void
    {
        self::assertSame(1440, Length::inch(1));
        self::assertSame(720, Length::inch(0.5));
        self::assertSame(2880, Length::inch(2));
    }

    #[Test]
    public function cm_to_twips(): void
    {
        // 1cm = 567 twips (rounded from 566.929)
        self::assertSame(567, Length::cm(1));
        self::assertSame(2835, Length::cm(5));
    }

    #[Test]
    public function mm_to_twips(): void
    {
        // 1mm ≈ 56.69 twips → 57
        self::assertSame(57, Length::mm(1));
        // 20mm ≈ 1134 twips
        self::assertSame(1134, Length::mm(20));
    }

    #[Test]
    public function px_to_twips_at_96dpi(): void
    {
        // 1 CSS px = 0.75 pt = 15 twips
        self::assertSame(15, Length::px(1));
        self::assertSame(150, Length::px(10));
        self::assertSame(1500, Length::px(100));
    }

    // ─────────── Convenience on TableBuilder ─────────────────────────────

    #[Test]
    public function table_widthPt_widthMm_widthCm_widthInches(): void
    {
        $doc = DocumentBuilder::new()
            ->table(fn (TableBuilder $t) => $t->widthMm(160)->row(['x']))
            ->build();
        /** @var Table $t */
        $t = $doc->section->body[0];
        // 160mm ≈ 9071 twips
        self::assertSame(Length::mm(160), $t->style->widthTwips);

        $doc2 = DocumentBuilder::new()
            ->table(fn (TableBuilder $t) => $t->widthCm(16)->row(['x']))
            ->build();
        /** @var Table $t2 */
        $t2 = $doc2->section->body[0];
        self::assertSame(Length::cm(16), $t2->style->widthTwips);

        $doc3 = DocumentBuilder::new()
            ->table(fn (TableBuilder $t) => $t->widthInches(6.25)->row(['x']))
            ->build();
        /** @var Table $t3 */
        $t3 = $doc3->section->body[0];
        self::assertSame(Length::inch(6.25), $t3->style->widthTwips);
    }

    #[Test]
    public function table_cellMarginsMm_cellMarginsPt(): void
    {
        $doc = DocumentBuilder::new()
            ->table(fn (TableBuilder $t) => $t->cellMarginsMm(2)->row(['x']))
            ->build();
        /** @var Table $t */
        $t = $doc->section->body[0];
        $expected = Length::mm(2);
        self::assertSame($expected, $t->style->cellMarginTopTwips);
        self::assertSame($expected, $t->style->cellMarginRightTwips);
        self::assertSame($expected, $t->style->cellMarginBottomTwips);
        self::assertSame($expected, $t->style->cellMarginLeftTwips);

        $doc2 = DocumentBuilder::new()
            ->table(fn (TableBuilder $t) => $t->cellMarginsPt(6, 4)->row(['x']))
            ->build();
        /** @var Table $t2 */
        $t2 = $doc2->section->body[0];
        self::assertSame(Length::pt(6), $t2->style->cellMarginTopTwips);
        self::assertSame(Length::pt(4), $t2->style->cellMarginRightTwips);
    }

    // ─────────── Convenience on TableCellBuilder ─────────────────────────

    #[Test]
    public function cell_widthMm_widthInches(): void
    {
        $doc = DocumentBuilder::new()
            ->table(fn (TableBuilder $t) => $t
                ->row(fn (TableRowBuilder $r) => $r
                    ->cell(fn (TableCellBuilder $c) => $c->widthMm(50)->paragraph('A'))
                    ->cell(fn (TableCellBuilder $c) => $c->widthInches(1.5)->paragraph('B'))
                )
            )
            ->build();
        /** @var Table $t */
        $t = $doc->section->body[0];
        self::assertSame(Length::mm(50), $t->rows[0]->cells[0]->style->widthTwips);
        self::assertSame(Length::inch(1.5), $t->rows[0]->cells[1]->style->widthTwips);
    }

    #[Test]
    public function cell_padding_mm_pt_cm_inches(): void
    {
        $doc = DocumentBuilder::new()
            ->table(fn (TableBuilder $t) => $t
                ->row(fn (TableRowBuilder $r) => $r
                    ->cell(fn (TableCellBuilder $c) => $c->paddingMm(2)->paragraph('a'))
                    ->cell(fn (TableCellBuilder $c) => $c->paddingPt(4, 8)->paragraph('b'))
                    ->cell(fn (TableCellBuilder $c) => $c->paddingCm(0.25)->paragraph('c'))
                    ->cell(fn (TableCellBuilder $c) => $c->paddingInches(0.1)->paragraph('d'))
                )
            )
            ->build();
        /** @var Table $t */
        $t = $doc->section->body[0];
        $cells = $t->rows[0]->cells;

        self::assertSame(Length::mm(2), $cells[0]->style->paddingTopTwips);
        self::assertSame(Length::pt(4), $cells[1]->style->paddingTopTwips);
        self::assertSame(Length::pt(8), $cells[1]->style->paddingRightTwips);
        self::assertSame(Length::cm(0.25), $cells[2]->style->paddingTopTwips);
        self::assertSame(Length::inch(0.1), $cells[3]->style->paddingTopTwips);
    }

    // ─────────── Convenience on ParagraphBuilder ─────────────────────────

    #[Test]
    public function paragraph_indent_units(): void
    {
        $doc = DocumentBuilder::new()
            ->paragraph(fn (ParagraphBuilder $p) => $p->indentMm(left: 10)->text('mm'))
            ->paragraph(fn (ParagraphBuilder $p) => $p->indentCm(left: 1)->text('cm'))
            ->paragraph(fn (ParagraphBuilder $p) => $p->indentPt(left: 28)->text('pt'))
            ->paragraph(fn (ParagraphBuilder $p) => $p->indentInches(left: 0.5)->text('in'))
            ->build();
        $body = $doc->section->body;
        self::assertSame(Length::mm(10), $body[0]->style->indentLeftTwips);
        self::assertSame(Length::cm(1), $body[1]->style->indentLeftTwips);
        self::assertSame(Length::pt(28), $body[2]->style->indentLeftTwips);
        self::assertSame(Length::inch(0.5), $body[3]->style->indentLeftTwips);
    }

    #[Test]
    public function paragraph_spacing_units(): void
    {
        $doc = DocumentBuilder::new()
            ->paragraph(fn (ParagraphBuilder $p) => $p->spacingPt(before: 6, after: 6)->text('x'))
            ->paragraph(fn (ParagraphBuilder $p) => $p->spacingMm(before: 2)->text('y'))
            ->build();
        $body = $doc->section->body;
        self::assertSame(Length::pt(6), $body[0]->style->spaceBeforeTwips);
        self::assertSame(Length::pt(6), $body[0]->style->spaceAfterTwips);
        self::assertSame(Length::mm(2), $body[1]->style->spaceBeforeTwips);
    }

    #[Test]
    public function length_helpers_usable_standalone(): void
    {
        // Length::cm(1) можно использовать как параметр для widthTwips
        $doc = DocumentBuilder::new()
            ->table(fn (TableBuilder $t) => $t
                ->columns(Length::cm(3), Length::cm(5))
                ->row(['A', 'B'])
            )
            ->build();
        /** @var Table $t */
        $t = $doc->section->body[0];
        self::assertSame([Length::cm(3), Length::cm(5)], $t->gridColumnsTwips);
    }
}
