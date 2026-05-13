<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Tests\Build;

use Dskripchenko\PhpDocx\Build\DocumentBuilder;
use Dskripchenko\PhpDocx\Build\ListBuilder;
use Dskripchenko\PhpDocx\Build\ListItemBuilder;
use Dskripchenko\PhpDocx\Build\ParagraphBuilder;
use Dskripchenko\PhpDocx\Build\RunStyleBuilder;
use Dskripchenko\PhpDocx\Element\Paragraph;
use Dskripchenko\PhpDocx\Element\Run;
use Dskripchenko\PhpDocx\Style\Alignment;
use Dskripchenko\PhpDocx\Style\Border;
use Dskripchenko\PhpDocx\Style\BorderSet;
use Dskripchenko\PhpDocx\Style\BorderStyle;
use Dskripchenko\PhpDocx\Style\RunStyle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RunStyleBuilderTest extends TestCase
{
    #[Test]
    public function builds_run_style_with_color_size_font(): void
    {
        $style = RunStyleBuilder::new()
            ->color('ff0000')
            ->fontSizePt(14.5)
            ->fontFamily('Arial')
            ->bold()
            ->build();
        self::assertSame('ff0000', $style->color);
        self::assertSame(29, $style->sizeHalfPoints); // 14.5 * 2
        self::assertSame('Arial', $style->fontFamily);
        self::assertTrue($style->bold);
    }

    #[Test]
    public function color_strips_hash_prefix(): void
    {
        $style = RunStyleBuilder::new()->color('#00FF00')->build();
        self::assertSame('00ff00', $style->color);
    }

    #[Test]
    public function from_existing_style_preserves_fields(): void
    {
        $existing = new RunStyle(sizeHalfPoints: 22, color: '111111', bold: true);
        $modified = RunStyleBuilder::from($existing)
            ->italic()
            ->build();
        self::assertSame(22, $modified->sizeHalfPoints);
        self::assertSame('111111', $modified->color);
        self::assertTrue($modified->bold);
        self::assertTrue($modified->italic);
    }

    #[Test]
    public function styled_in_paragraph_builder(): void
    {
        $doc = DocumentBuilder::new()
            ->paragraph(fn (ParagraphBuilder $p) => $p
                ->text('plain ')
                ->styled('red bold', fn (RunStyleBuilder $s) => $s
                    ->color('ff0000')
                    ->bold()
                )
            )
            ->build();
        /** @var Paragraph $p */
        $p = $doc->section->body[0];
        /** @var Run $styledRun */
        $styledRun = $p->children[1];
        self::assertSame('red bold', $styledRun->text);
        self::assertSame('ff0000', $styledRun->style->color);
        self::assertTrue($styledRun->style->bold);
    }

    #[Test]
    public function paragraph_alignment_shortcuts(): void
    {
        $doc = DocumentBuilder::new()
            ->paragraph(fn (ParagraphBuilder $p) => $p->alignCenter()->text('center'))
            ->paragraph(fn (ParagraphBuilder $p) => $p->alignRight()->text('right'))
            ->paragraph(fn (ParagraphBuilder $p) => $p->alignJustify()->text('just'))
            ->build();

        self::assertSame(Alignment::Center, $doc->section->body[0]->style->alignment);
        self::assertSame(Alignment::End, $doc->section->body[1]->style->alignment);
        self::assertSame(Alignment::Justify, $doc->section->body[2]->style->alignment);
    }

    #[Test]
    public function paragraph_indent_in_twips(): void
    {
        $doc = DocumentBuilder::new()
            ->paragraph(fn (ParagraphBuilder $p) => $p
                ->indent(left: 720, firstLine: -360)
                ->text('hanging')
            )
            ->build();
        $s = $doc->section->body[0]->style;
        self::assertSame(720, $s->indentLeftTwips);
        self::assertSame(-360, $s->indentFirstLineTwips);
    }

    #[Test]
    public function paragraph_indent_in_mm(): void
    {
        $doc = DocumentBuilder::new()
            ->paragraph(fn (ParagraphBuilder $p) => $p
                ->indentMm(left: 10.0)
                ->text('x')
            )
            ->build();
        $s = $doc->section->body[0]->style;
        // 10mm ≈ 567 twips
        self::assertSame(567, $s->indentLeftTwips);
    }

    #[Test]
    public function paragraph_spacing(): void
    {
        $doc = DocumentBuilder::new()
            ->paragraph(fn (ParagraphBuilder $p) => $p
                ->spacing(before: 200, after: 100, line: 360)
                ->text('x')
            )
            ->build();
        $s = $doc->section->body[0]->style;
        self::assertSame(200, $s->spaceBeforeTwips);
        self::assertSame(100, $s->spaceAfterTwips);
        self::assertSame(360, $s->lineSpacingTwips);
    }

    #[Test]
    public function paragraph_borders(): void
    {
        $borders = new BorderSet(
            top: new Border(BorderStyle::Single, 4, '000000'),
            bottom: new Border(BorderStyle::Single, 4, '000000'),
        );
        $doc = DocumentBuilder::new()
            ->paragraph(fn (ParagraphBuilder $p) => $p
                ->borders($borders)
                ->text('framed')
            )
            ->build();
        self::assertSame($borders, $doc->section->body[0]->style->borders);
    }

    #[Test]
    public function highlight_yellow(): void
    {
        $doc = DocumentBuilder::new()
            ->paragraph(fn (ParagraphBuilder $p) => $p
                ->styled('warning', fn (RunStyleBuilder $s) => $s->highlight('yellow'))
            )
            ->build();
        self::assertSame('yellow', $doc->section->body[0]->children[0]->style->highlight);
    }

    #[Test]
    public function superscript_clears_subscript_and_vice_versa(): void
    {
        $s = RunStyleBuilder::new()->subscript()->superscript()->build();
        self::assertTrue($s->superscript);
        self::assertFalse($s->subscript);
    }

    #[Test]
    public function styled_in_list_item(): void
    {
        $doc = DocumentBuilder::new()
            ->bulletList(fn (ListBuilder $l) => $l
                ->item(fn (ListItemBuilder $i) => $i
                    ->text('Note: ')
                    ->styled('emphasised', fn (RunStyleBuilder $s) => $s->italic()->color('888888'))
                )
            )
            ->build();
        /** @var \Dskripchenko\PhpDocx\Element\ListNode $list */
        $list = $doc->section->body[0];
        $secondRun = $list->items[0]->children[1];
        self::assertTrue($secondRun->style->italic);
        self::assertSame('888888', $secondRun->style->color);
    }
}
