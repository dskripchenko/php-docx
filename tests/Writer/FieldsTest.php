<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Tests\Writer;

use Dskripchenko\PhpDocx\Element\Field;
use Dskripchenko\PhpDocx\Element\Paragraph;
use Dskripchenko\PhpDocx\Element\Run;
use Dskripchenko\PhpDocx\Html\Converter;
use Dskripchenko\PhpDocx\Writer\BodyXmlBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FieldsTest extends TestCase
{
    #[Test]
    public function page_field_emits_fldSimple_PAGE(): void
    {
        $xml = (new BodyXmlBuilder)->render([
            new Paragraph([new Run('Стр. '), Field::page(), new Run(' из '), Field::pageTotal()]),
        ]);

        self::assertStringContainsString('<w:fldSimple w:instr=" PAGE \\* MERGEFORMAT "', $xml);
        self::assertStringContainsString('<w:fldSimple w:instr=" NUMPAGES \\* MERGEFORMAT "', $xml);
    }

    #[Test]
    public function date_field_emits_DATE_with_format(): void
    {
        $xml = (new BodyXmlBuilder)->render([
            new Paragraph([Field::date('dd MMMM yyyy')]),
        ]);
        // Кавычки эскейпятся в XML-атрибут как &quot;.
        self::assertStringContainsString('DATE \\@ &quot;dd MMMM yyyy&quot;', $xml);
    }

    #[Test]
    public function converter_maps_page_number_tag(): void
    {
        $doc = (new Converter)->fromHtml('<p>Page <page-number/> of <page-total/></p>');
        $children = $doc->section->body[0]->children;

        $fields = array_filter($children, fn ($c) => $c instanceof Field);
        self::assertCount(2, $fields);
        $fieldsList = array_values($fields);
        self::assertStringContainsString('PAGE', $fieldsList[0]->instruction);
        self::assertStringContainsString('NUMPAGES', $fieldsList[1]->instruction);
    }

    #[Test]
    public function converter_maps_current_date_with_custom_format(): void
    {
        $doc = (new Converter)->fromHtml('<p>Date: <current-date format="yyyy-MM-dd"/></p>');
        $field = null;
        foreach ($doc->section->body[0]->children as $c) {
            if ($c instanceof Field) {
                $field = $c;
                break;
            }
        }
        self::assertNotNull($field);
        self::assertStringContainsString('yyyy-MM-dd', $field->instruction);
    }
}
