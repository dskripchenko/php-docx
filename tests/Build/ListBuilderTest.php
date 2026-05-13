<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Tests\Build;

use Dskripchenko\PhpDocx\Build\DocumentBuilder;
use Dskripchenko\PhpDocx\Build\ListBuilder;
use Dskripchenko\PhpDocx\Build\ListItemBuilder;
use Dskripchenko\PhpDocx\Element\ListFormat;
use Dskripchenko\PhpDocx\Element\ListNode;
use Dskripchenko\PhpDocx\Element\Run;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ListBuilderTest extends TestCase
{
    #[Test]
    public function bullet_list_short_form(): void
    {
        $doc = DocumentBuilder::new()
            ->bulletList(fn (ListBuilder $l) => $l
                ->item('Apple')
                ->item('Banana')
                ->item('Cherry')
            )
            ->build();
        /** @var ListNode $list */
        $list = $doc->section->body[0];
        self::assertInstanceOf(ListNode::class, $list);
        self::assertFalse($list->ordered);
        self::assertCount(3, $list->items);
        self::assertSame('Apple', $list->items[0]->children[0]->text);
    }

    #[Test]
    public function ordered_list_default_decimal(): void
    {
        $doc = DocumentBuilder::new()
            ->orderedList(fn (ListBuilder $l) => $l->item('one')->item('two'))
            ->build();
        /** @var ListNode $list */
        $list = $doc->section->body[0];
        self::assertTrue($list->ordered);
        self::assertSame(ListFormat::Decimal, $list->effectiveFormat());
        self::assertSame(1, $list->startAt);
    }

    #[Test]
    public function ordered_list_lower_letter_with_start(): void
    {
        $doc = DocumentBuilder::new()
            ->orderedList(fn (ListBuilder $l) => $l
                ->format(ListFormat::LowerLetter)
                ->startAt(3)
                ->item('first')
                ->item('second')
            )
            ->build();
        /** @var ListNode $list */
        $list = $doc->section->body[0];
        self::assertSame(ListFormat::LowerLetter, $list->effectiveFormat());
        self::assertSame(3, $list->startAt);
    }

    #[Test]
    public function nested_list_inherits_ordered_from_parent(): void
    {
        $doc = DocumentBuilder::new()
            ->bulletList(fn (ListBuilder $l) => $l
                ->item('Top1')
                ->item('Top2', fn (ListBuilder $n) => $n
                    ->item('Sub A')
                    ->item('Sub B')
                )
                ->item('Top3')
            )
            ->build();
        /** @var ListNode $list */
        $list = $doc->section->body[0];

        self::assertCount(3, $list->items);
        // 2-й item имеет nested
        $second = $list->items[1];
        self::assertNotNull($second->nestedList);
        self::assertFalse($second->nestedList->ordered); // унаследовал bullet
        self::assertCount(2, $second->nestedList->items);
        self::assertSame('Sub A', $second->nestedList->items[0]->children[0]->text);
    }

    #[Test]
    public function item_closure_form_supports_inline_styles(): void
    {
        $doc = DocumentBuilder::new()
            ->bulletList(fn (ListBuilder $l) => $l
                ->item(fn (ListItemBuilder $i) => $i
                    ->text('Important: ')
                    ->bold('do not skip')
                    ->text('!')
                )
            )
            ->build();
        /** @var ListNode $list */
        $list = $doc->section->body[0];
        $item = $list->items[0];
        self::assertCount(3, $item->children);
        self::assertFalse($item->children[0]->style->bold);
        self::assertTrue($item->children[1]->style->bold);
    }

    #[Test]
    public function deeply_nested_lists(): void
    {
        $doc = DocumentBuilder::new()
            ->bulletList(fn (ListBuilder $l) => $l
                ->item('L0', fn (ListBuilder $l1) => $l1
                    ->item('L1', fn (ListBuilder $l2) => $l2
                        ->item('L2')
                    )
                )
            )
            ->build();
        /** @var ListNode $list */
        $list = $doc->section->body[0];
        $l1 = $list->items[0]->nestedList;
        self::assertNotNull($l1);
        $l2 = $l1->items[0]->nestedList;
        self::assertNotNull($l2);
        self::assertSame('L2', $l2->items[0]->children[0]->text);
    }

    #[Test]
    public function bullet_list_writes_to_valid_docx(): void
    {
        $bytes = DocumentBuilder::new()
            ->bulletList(fn (ListBuilder $l) => $l->item('A')->item('B'))
            ->toBytes();
        self::assertSame('PK', substr($bytes, 0, 2));
    }

    #[Test]
    public function mixed_blocks_with_list(): void
    {
        $doc = DocumentBuilder::new()
            ->heading(1, 'Title')
            ->paragraph('Intro')
            ->bulletList(fn (ListBuilder $l) => $l->item('a')->item('b'))
            ->paragraph('Outro')
            ->build();
        $body = $doc->section->body;
        self::assertCount(4, $body);
        self::assertSame(1, $body[0]->headingLevel);
        self::assertInstanceOf(ListNode::class, $body[2]);
    }

    #[Test]
    public function list_inside_table_cell(): void
    {
        $doc = DocumentBuilder::new()
            ->table(fn ($t) => $t
                ->row(fn ($r) => $r
                    ->cell(fn ($c) => $c
                        ->paragraph('Items:')
                        ->bulletList(fn (ListBuilder $l) => $l->item('A')->item('B'))
                    )
                )
            )
            ->build();
        /** @var \Dskripchenko\PhpDocx\Element\Table $t */
        $t = $doc->section->body[0];
        $cell = $t->rows[0]->cells[0];
        $hasList = false;
        foreach ($cell->children as $b) {
            if ($b instanceof ListNode) {
                $hasList = true;
            }
        }
        self::assertTrue($hasList);
    }

    #[Test]
    public function upper_roman_ordered_list(): void
    {
        $doc = DocumentBuilder::new()
            ->orderedList(fn (ListBuilder $l) => $l
                ->format(ListFormat::UpperRoman)
                ->item('I')
                ->item('II')
            )
            ->build();
        /** @var ListNode $list */
        $list = $doc->section->body[0];
        self::assertSame(ListFormat::UpperRoman, $list->effectiveFormat());
    }
}
