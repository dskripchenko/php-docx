<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Tests\Build;

use Dskripchenko\PhpDocx\Build\ColumnBuilder;
use Dskripchenko\PhpDocx\Build\DocumentBuilder;
use Dskripchenko\PhpDocx\Build\Length;
use Dskripchenko\PhpDocx\Build\TableBuilder;
use Dskripchenko\PhpDocx\Element\Table;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ColumnBuilderTest extends TestCase
{
    #[Test]
    public function single_column_appended(): void
    {
        $doc = DocumentBuilder::new()
            ->table(fn (TableBuilder $t) => $t
                ->column(fn (ColumnBuilder $c) => $c->widthCm(3))
                ->row(['A'])
            )
            ->build();
        /** @var Table $t */
        $t = $doc->section->body[0];
        self::assertSame([Length::cm(3)], $t->gridColumnsTwips);
    }

    #[Test]
    public function multiple_columns_via_individual_builders(): void
    {
        $doc = DocumentBuilder::new()
            ->table(fn (TableBuilder $t) => $t
                ->column(fn (ColumnBuilder $c) => $c->widthMm(20))
                ->column(fn (ColumnBuilder $c) => $c->widthCm(5))
                ->column(fn (ColumnBuilder $c) => $c->widthInches(1))
                ->row(['A', 'B', 'C'])
            )
            ->build();
        /** @var Table $t */
        $t = $doc->section->body[0];
        self::assertSame(
            [Length::mm(20), Length::cm(5), Length::inch(1)],
            $t->gridColumnsTwips,
        );
    }

    #[Test]
    public function column_default_width_when_not_set(): void
    {
        $doc = DocumentBuilder::new()
            ->table(fn (TableBuilder $t) => $t
                ->column(fn (ColumnBuilder $c) => $c) // no-op
                ->row(['x'])
            )
            ->build();
        /** @var Table $t */
        $t = $doc->section->body[0];
        // Default 2000 twips
        self::assertSame([2000], $t->gridColumnsTwips);
    }

    #[Test]
    public function column_widthPx_and_widthPt(): void
    {
        $doc = DocumentBuilder::new()
            ->table(fn (TableBuilder $t) => $t
                ->column(fn (ColumnBuilder $c) => $c->widthPx(100))
                ->column(fn (ColumnBuilder $c) => $c->widthPt(72))
                ->row(['A', 'B'])
            )
            ->build();
        /** @var Table $t */
        $t = $doc->section->body[0];
        self::assertSame([Length::px(100), Length::pt(72)], $t->gridColumnsTwips);
    }

    #[Test]
    public function columns_short_form_replaces_previous_columns(): void
    {
        // columns(int ...) — заменяет, не добавляет.
        $doc = DocumentBuilder::new()
            ->table(fn (TableBuilder $t) => $t
                ->column(fn (ColumnBuilder $c) => $c->widthCm(3))
                ->columns(1000, 2000) // replace
                ->row(['A', 'B'])
            )
            ->build();
        /** @var Table $t */
        $t = $doc->section->body[0];
        self::assertSame([1000, 2000], $t->gridColumnsTwips);
    }

    #[Test]
    public function column_widthTwips_explicit(): void
    {
        $col = new ColumnBuilder;
        $col->widthTwips(1500);
        self::assertSame(1500, $col->build());
    }

    #[Test]
    public function mixed_column_short_then_append(): void
    {
        // columns() replaces, потом column() append'ит.
        $doc = DocumentBuilder::new()
            ->table(fn (TableBuilder $t) => $t
                ->columns(1000, 2000)
                ->column(fn (ColumnBuilder $c) => $c->widthCm(3))
                ->row(['A', 'B', 'C'])
            )
            ->build();
        /** @var Table $t */
        $t = $doc->section->body[0];
        self::assertSame([1000, 2000, Length::cm(3)], $t->gridColumnsTwips);
    }

    #[Test]
    public function negative_width_clamped_to_zero(): void
    {
        $col = new ColumnBuilder;
        $col->widthTwips(-100);
        self::assertSame(0, $col->build());
    }
}
