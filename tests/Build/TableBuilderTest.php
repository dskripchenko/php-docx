<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Tests\Build;

use Dskripchenko\PhpDocx\Build\DocumentBuilder;
use Dskripchenko\PhpDocx\Build\ParagraphBuilder;
use Dskripchenko\PhpDocx\Build\TableBuilder;
use Dskripchenko\PhpDocx\Build\TableCellBuilder;
use Dskripchenko\PhpDocx\Build\TableRowBuilder;
use Dskripchenko\PhpDocx\Element\Paragraph;
use Dskripchenko\PhpDocx\Element\Run;
use Dskripchenko\PhpDocx\Element\Table;
use Dskripchenko\PhpDocx\Style\Alignment;
use Dskripchenko\PhpDocx\Style\VerticalAlign;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TableBuilderTest extends TestCase
{
    #[Test]
    public function simple_2x2_via_string_arrays(): void
    {
        $doc = DocumentBuilder::new()
            ->table(fn (TableBuilder $t) => $t
                ->headerRow(['A', 'B'])
                ->row(['1', '2'])
            )
            ->build();

        /** @var Table $table */
        $table = $doc->section->body[0];
        self::assertInstanceOf(Table::class, $table);
        self::assertCount(2, $table->rows);
        self::assertTrue($table->rows[0]->isHeader);
        self::assertFalse($table->rows[1]->isHeader);
        self::assertSame('A', $this->cellText($table, 0, 0));
        self::assertSame('2', $this->cellText($table, 1, 1));
    }

    #[Test]
    public function rich_cell_via_closure(): void
    {
        $doc = DocumentBuilder::new()
            ->table(fn (TableBuilder $t) => $t
                ->row(fn (TableRowBuilder $r) => $r
                    ->cell('plain')
                    ->cell(fn (TableCellBuilder $c) => $c
                        ->backgroundColor('ffeb3b')
                        ->paragraph(fn (ParagraphBuilder $p) => $p->bold('rich'))
                    )
                )
            )
            ->build();

        /** @var Table $table */
        $table = $doc->section->body[0];
        $cellA = $table->rows[0]->cells[0];
        $cellB = $table->rows[0]->cells[1];

        self::assertNull($cellA->style->backgroundColor);
        self::assertSame('ffeb3b', $cellB->style->backgroundColor);

        $richP = $cellB->children[0];
        self::assertInstanceOf(Paragraph::class, $richP);
        self::assertTrue($richP->children[0]->style->bold);
        self::assertSame('rich', $richP->children[0]->text);
    }

    #[Test]
    public function caption_and_columns(): void
    {
        $doc = DocumentBuilder::new()
            ->table(fn (TableBuilder $t) => $t
                ->caption('Sales 2026')
                ->columns(1500, 3000, 2500)
                ->headerRow(['A', 'B', 'C'])
            )
            ->build();
        /** @var Table $table */
        $table = $doc->section->body[0];
        self::assertSame('Sales 2026', $table->caption);
        self::assertSame([1500, 3000, 2500], $table->gridColumnsTwips);
    }

    #[Test]
    public function table_widths_percent_and_twips(): void
    {
        $doc = DocumentBuilder::new()
            ->table(fn (TableBuilder $t) => $t->widthPercent(80)->row(['x']))
            ->build();
        /** @var Table $t */
        $t = $doc->section->body[0];
        self::assertNull($t->style->widthTwips);
        // 80% * 50 = 4000 (OOXML convention)
        self::assertSame(4000, $t->style->widthPercent);

        $doc2 = DocumentBuilder::new()
            ->table(fn (TableBuilder $t) => $t->widthTwips(9000)->row(['x']))
            ->build();
        /** @var Table $t2 */
        $t2 = $doc2->section->body[0];
        self::assertSame(9000, $t2->style->widthTwips);
        self::assertNull($t2->style->widthPercent);
    }

    #[Test]
    public function cell_gridSpan_and_rowSpan(): void
    {
        $doc = DocumentBuilder::new()
            ->table(fn (TableBuilder $t) => $t
                ->row(fn (TableRowBuilder $r) => $r
                    ->cell(fn (TableCellBuilder $c) => $c->gridSpan(3)->paragraph('Wide'))
                )
                ->row(fn (TableRowBuilder $r) => $r
                    ->cell(fn (TableCellBuilder $c) => $c->rowSpan(2)->paragraph('Tall'))
                    ->cell('Right')
                )
            )
            ->build();
        /** @var Table $t */
        $t = $doc->section->body[0];
        self::assertSame(3, $t->rows[0]->cells[0]->style->gridSpan);
        self::assertSame(2, $t->rows[1]->cells[0]->style->rowSpan);
    }

    #[Test]
    public function cell_padding_shorthand(): void
    {
        $doc = DocumentBuilder::new()
            ->table(fn (TableBuilder $t) => $t
                ->row(fn (TableRowBuilder $r) => $r
                    ->cell(fn (TableCellBuilder $c) => $c->padding(120)->paragraph('p'))
                    ->cell(fn (TableCellBuilder $c) => $c->padding(100, 50, 100, 50)->paragraph('q'))
                )
            )
            ->build();
        /** @var Table $t */
        $t = $doc->section->body[0];
        $padAll = $t->rows[0]->cells[0]->style;
        self::assertSame(120, $padAll->paddingTopTwips);
        self::assertSame(120, $padAll->paddingRightTwips);
        self::assertSame(120, $padAll->paddingBottomTwips);
        self::assertSame(120, $padAll->paddingLeftTwips);

        $padIndividual = $t->rows[0]->cells[1]->style;
        self::assertSame(100, $padIndividual->paddingTopTwips);
        self::assertSame(50, $padIndividual->paddingRightTwips);
        self::assertSame(100, $padIndividual->paddingBottomTwips);
        self::assertSame(50, $padIndividual->paddingLeftTwips);
    }

    #[Test]
    public function cell_valign_center(): void
    {
        $doc = DocumentBuilder::new()
            ->table(fn (TableBuilder $t) => $t
                ->row(fn (TableRowBuilder $r) => $r
                    ->cell(fn (TableCellBuilder $c) => $c->valignCenter()->paragraph('x'))
                )
            )
            ->build();
        /** @var Table $t */
        $t = $doc->section->body[0];
        self::assertSame(VerticalAlign::Center, $t->rows[0]->cells[0]->style->verticalAlign);
    }

    #[Test]
    public function table_alignment_center(): void
    {
        $doc = DocumentBuilder::new()
            ->table(fn (TableBuilder $t) => $t->alignCenter()->row(['x']))
            ->build();
        /** @var Table $t */
        $t = $doc->section->body[0];
        self::assertSame(Alignment::Center, $t->style->alignment);
    }

    #[Test]
    public function row_height_and_explicit_header_flag(): void
    {
        $doc = DocumentBuilder::new()
            ->table(fn (TableBuilder $t) => $t
                ->row(fn (TableRowBuilder $r) => $r->header()->height(800)->cell('H'))
            )
            ->build();
        /** @var Table $t */
        $t = $doc->section->body[0];
        self::assertTrue($t->rows[0]->isHeader);
        self::assertSame(800, $t->rows[0]->heightTwips);
    }

    #[Test]
    public function nested_table_in_cell(): void
    {
        $doc = DocumentBuilder::new()
            ->table(fn (TableBuilder $outer) => $outer
                ->row(fn (TableRowBuilder $r) => $r
                    ->cell(fn (TableCellBuilder $c) => $c
                        ->paragraph('outer')
                        ->table(fn (TableBuilder $inner) => $inner
                            ->row(['nested'])
                        )
                    )
                )
            )
            ->build();
        /** @var Table $outer */
        $outer = $doc->section->body[0];
        $cell = $outer->rows[0]->cells[0];

        $nested = null;
        foreach ($cell->children as $b) {
            if ($b instanceof Table) {
                $nested = $b;
            }
        }
        self::assertNotNull($nested);
        self::assertSame('nested', $this->cellText($nested, 0, 0));
    }

    #[Test]
    public function headerRow_closure_form(): void
    {
        $doc = DocumentBuilder::new()
            ->table(fn (TableBuilder $t) => $t
                ->headerRow(fn (TableRowBuilder $r) => $r
                    ->cell(fn (TableCellBuilder $c) => $c
                        ->backgroundColor('e0e0e0')
                        ->paragraph(fn (ParagraphBuilder $p) => $p->bold('H'))
                    )
                )
            )
            ->build();
        /** @var Table $t */
        $t = $doc->section->body[0];
        self::assertTrue($t->rows[0]->isHeader);
        self::assertSame('e0e0e0', $t->rows[0]->cells[0]->style->backgroundColor);
    }

    #[Test]
    public function table_writes_to_valid_docx(): void
    {
        $bytes = DocumentBuilder::new()
            ->table(fn (TableBuilder $t) => $t
                ->headerRow(['A', 'B'])
                ->row(['1', '2'])
            )
            ->toBytes();
        self::assertSame('PK', substr($bytes, 0, 2));
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
