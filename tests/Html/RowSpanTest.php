<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Tests\Html;

use Dskripchenko\PhpDocx\Element\Table;
use Dskripchenko\PhpDocx\Html\Converter;
use Dskripchenko\PhpDocx\Writer\BodyXmlBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RowSpanTest extends TestCase
{
    #[Test]
    public function rowspan_2_inserts_continue_cell_in_next_row(): void
    {
        $html = '<table>'
            .'<tr><td rowspan="2">Merged</td><td>R1C2</td></tr>'
            .'<tr><td>R2C2</td></tr>'
            .'</table>';
        $doc = (new Converter)->fromHtml($html);
        /** @var Table $t */
        $t = $doc->section->body[0];

        self::assertCount(2, $t->rows);
        // Row 1: merged cell + 1 regular = 2 cells
        self::assertCount(2, $t->rows[0]->cells);
        self::assertSame(2, $t->rows[0]->cells[0]->style->rowSpan);

        // Row 2: continue-cell (auto-inserted) + 1 regular = 2 cells
        self::assertCount(2, $t->rows[1]->cells);
        self::assertTrue($t->rows[1]->cells[0]->style->vMergeContinue);
    }

    #[Test]
    public function rowspan_emits_correct_xml(): void
    {
        $html = '<table>'
            .'<tr><td rowspan="2">Merged</td><td>R1C2</td></tr>'
            .'<tr><td>R2C2</td></tr>'
            .'</table>';
        $doc = (new Converter)->fromHtml($html);
        $xml = (new BodyXmlBuilder)->render($doc->section->body);

        // First cell — restart
        self::assertStringContainsString('<w:vMerge w:val="restart"/>', $xml);
        // The continue cell in row 2 carries no val
        self::assertMatchesRegularExpression('/<w:vMerge\/>/', $xml);
    }

    #[Test]
    public function rowspan_3_inserts_two_continue_cells(): void
    {
        $html = '<table>'
            .'<tr><td rowspan="3">Big</td><td>R1</td></tr>'
            .'<tr><td>R2</td></tr>'
            .'<tr><td>R3</td></tr>'
            .'</table>';
        $doc = (new Converter)->fromHtml($html);
        /** @var Table $t */
        $t = $doc->section->body[0];

        self::assertCount(2, $t->rows[1]->cells);
        self::assertTrue($t->rows[1]->cells[0]->style->vMergeContinue);
        self::assertCount(2, $t->rows[2]->cells);
        self::assertTrue($t->rows[2]->cells[0]->style->vMergeContinue);
    }

    #[Test]
    public function rowspan_with_middle_column_inserts_continue_at_right_position(): void
    {
        // 3 columns: middle one has rowspan=2
        $html = '<table>'
            .'<tr><td>R1A</td><td rowspan="2">MERGED</td><td>R1C</td></tr>'
            .'<tr><td>R2A</td><td>R2C</td></tr>'
            .'</table>';
        $doc = (new Converter)->fromHtml($html);
        /** @var Table $t */
        $t = $doc->section->body[0];

        // Row 2 has to become R2A, [continue], R2C — 3 cells
        self::assertCount(3, $t->rows[1]->cells);
        self::assertFalse($t->rows[1]->cells[0]->style->vMergeContinue, 'R2A regular');
        self::assertTrue($t->rows[1]->cells[1]->style->vMergeContinue, 'middle is continue');
        self::assertFalse($t->rows[1]->cells[2]->style->vMergeContinue, 'R2C regular');
    }

    #[Test]
    public function no_rowspan_no_continue_inserts(): void
    {
        $doc = (new Converter)->fromHtml('<table><tr><td>A</td></tr><tr><td>B</td></tr></table>');
        /** @var Table $t */
        $t = $doc->section->body[0];

        foreach ($t->rows as $row) {
            self::assertCount(1, $row->cells);
            self::assertFalse($row->cells[0]->style->vMergeContinue);
        }
    }
}
