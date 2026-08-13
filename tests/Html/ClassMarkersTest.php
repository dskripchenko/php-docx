<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Tests\Html;

use Dskripchenko\PhpDocx\Element\Field;
use Dskripchenko\PhpDocx\Element\PageBreak;
use Dskripchenko\PhpDocx\Element\Paragraph;
use Dskripchenko\PhpDocx\Html\Converter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Fields and breaks marked up with a class rather than a tag of their own.
 *
 * Custom tags (`<page-number/>`) do not survive every editor: a whitelist-based
 * markup cleanup throws an unknown tag away, while a class on a `span` stays.
 * That is why both notations are equal citizens.
 */
final class ClassMarkersTest extends TestCase
{
    #[Test]
    public function span_with_page_number_class_becomes_field_inline(): void
    {
        $doc = (new Converter)->fromHtml(
            '<p>Стр. <span class="page-number"></span> из <span class="page-total"></span></p>',
        );

        // A single paragraph: the fields stay in the line of text instead of breaking it.
        self::assertCount(1, $doc->section->body);
        $paragraph = $doc->section->body[0];
        self::assertInstanceOf(Paragraph::class, $paragraph);

        $fields = array_values(array_filter(
            $paragraph->children,
            static fn ($child): bool => $child instanceof Field,
        ));
        self::assertCount(2, $fields);
        self::assertStringContainsString('PAGE', $fields[0]->instruction);
        self::assertStringContainsString('NUMPAGES', $fields[1]->instruction);
    }

    #[Test]
    public function span_field_keeps_surrounding_run_style(): void
    {
        $doc = (new Converter)->fromHtml(
            '<p style="font-size:8pt"><b>№ <span class="page-number"></span></b></p>',
        );

        $field = null;
        foreach ($doc->section->body[0]->children as $child) {
            if ($child instanceof Field) {
                $field = $child;
            }
        }

        self::assertNotNull($field);
        self::assertTrue($field->style->bold);
        self::assertSame(16, $field->style->sizeHalfPoints);
    }

    #[Test]
    public function div_with_page_break_class_becomes_page_break(): void
    {
        $doc = (new Converter)->fromHtml('<p>до</p><div class="page-break"></div><p>после</p>');

        self::assertInstanceOf(PageBreak::class, $doc->section->body[1]);
    }

    #[Test]
    public function div_with_page_number_class_becomes_paragraph_with_field(): void
    {
        $doc = (new Converter)->fromHtml('<div class="page-total"></div>');

        $paragraph = $doc->section->body[0];
        self::assertInstanceOf(Paragraph::class, $paragraph);
        self::assertInstanceOf(Field::class, $paragraph->children[0]);
        self::assertStringContainsString('NUMPAGES', $paragraph->children[0]->instruction);
    }

    #[Test]
    public function plain_span_without_marker_class_is_untouched(): void
    {
        $doc = (new Converter)->fromHtml('<p>обычный <span class="lead">текст</span></p>');

        foreach ($doc->section->body[0]->children as $child) {
            self::assertNotInstanceOf(Field::class, $child);
        }
    }
}
