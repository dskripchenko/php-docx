<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Tests\Writer;

use Dskripchenko\PhpDocx\Document;
use Dskripchenko\PhpDocx\Element\ListItem;
use Dskripchenko\PhpDocx\Element\ListNode;
use Dskripchenko\PhpDocx\Element\Paragraph;
use Dskripchenko\PhpDocx\Element\Run;
use Dskripchenko\PhpDocx\Html\Converter;
use Dskripchenko\PhpDocx\Section;
use Dskripchenko\PhpDocx\Writer\BodyXmlBuilder;
use Dskripchenko\PhpDocx\Writer\NumberingXmlBuilder;
use Dskripchenko\PhpDocx\Writer\Word2007Writer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ListsTest extends TestCase
{
    #[Test]
    public function numbering_xml_builder_renders_abstract_and_concrete_nums(): void
    {
        $xml = (new NumberingXmlBuilder)->render();

        self::assertStringContainsString('<w:numbering', $xml);
        self::assertStringContainsString('<w:abstractNum w:abstractNumId="0">', $xml); // bullet
        self::assertStringContainsString('<w:abstractNum w:abstractNumId="1">', $xml); // ordered
        self::assertStringContainsString('<w:num w:numId="1">', $xml);                  // bullet inst
        self::assertStringContainsString('<w:num w:numId="2">', $xml);                  // ordered inst
        // Уровни
        self::assertStringContainsString('w:ilvl="0"', $xml);
        self::assertStringContainsString('w:ilvl="1"', $xml);
        self::assertStringContainsString('w:ilvl="2"', $xml);
        // Bullet symbols
        self::assertStringContainsString('●', $xml);
        // Numbering formats
        self::assertStringContainsString('w:val="decimal"', $xml);
        self::assertStringContainsString('w:val="lowerLetter"', $xml);
        self::assertStringContainsString('w:val="lowerRoman"', $xml);
    }

    #[Test]
    public function body_builder_emits_numPr_for_list_items(): void
    {
        $builder = new BodyXmlBuilder;
        $xml = $builder->render([
            new ListNode([
                new ListItem([new Run('A')]),
                new ListItem([new Run('B')]),
            ], ordered: false),
        ]);

        self::assertStringContainsString('<w:pStyle w:val="ListParagraph"/>', $xml);
        self::assertStringContainsString('<w:numPr>', $xml);
        self::assertStringContainsString('<w:ilvl w:val="0"/>', $xml);
        // bullet → numId=1
        self::assertStringContainsString('<w:numId w:val="1"/>', $xml);
        self::assertTrue($builder->usesNumbering());
    }

    #[Test]
    public function ordered_list_uses_numId_2(): void
    {
        $builder = new BodyXmlBuilder;
        $xml = $builder->render([
            new ListNode([new ListItem([new Run('First')])], ordered: true),
        ]);

        self::assertStringContainsString('<w:numId w:val="2"/>', $xml);
    }

    #[Test]
    public function nested_list_emits_incremented_level(): void
    {
        $xml = (new BodyXmlBuilder)->render([
            new ListNode([
                new ListItem(
                    children: [new Run('Outer')],
                    nestedList: new ListNode([
                        new ListItem([new Run('Inner')]),
                    ], ordered: false),
                ),
            ]),
        ]);

        self::assertStringContainsString('<w:ilvl w:val="0"/>', $xml);
        self::assertStringContainsString('<w:ilvl w:val="1"/>', $xml);
        self::assertStringContainsString('Outer', $xml);
        self::assertStringContainsString('Inner', $xml);
    }

    #[Test]
    public function writer_emits_numbering_xml_part_when_lists_used(): void
    {
        $doc = new Document(new Section(
            body: [
                new ListNode([new ListItem([new Run('A')])]),
            ],
        ));
        $bytes = (new Word2007Writer)->write($doc);

        $names = self::zipEntries($bytes);
        self::assertContains('word/numbering.xml', $names);

        $contentTypes = self::extract($bytes, '[Content_Types].xml');
        self::assertStringContainsString('numbering+xml', $contentTypes);

        $rels = self::extract($bytes, 'word/_rels/document.xml.rels');
        self::assertStringContainsString('Target="numbering.xml"', $rels);
    }

    #[Test]
    public function writer_does_not_emit_numbering_when_no_lists(): void
    {
        $doc = new Document(new Section(body: [new Paragraph([new Run('x')])]));
        $bytes = (new Word2007Writer)->write($doc);

        $names = self::zipEntries($bytes);
        self::assertNotContains('word/numbering.xml', $names);
    }

    #[Test]
    public function converter_parses_ul_li_into_list_node(): void
    {
        $doc = (new Converter)->fromHtml('<ul><li>A</li><li>B</li></ul>');
        $body = $doc->section->body;

        self::assertCount(1, $body);
        self::assertInstanceOf(ListNode::class, $body[0]);
        self::assertFalse($body[0]->ordered);
        self::assertCount(2, $body[0]->items);
        self::assertSame('A', $body[0]->items[0]->children[0]->text);
        self::assertSame('B', $body[0]->items[1]->children[0]->text);
    }

    #[Test]
    public function converter_parses_ol_as_ordered(): void
    {
        $doc = (new Converter)->fromHtml('<ol><li>One</li><li>Two</li></ol>');
        self::assertTrue($doc->section->body[0]->ordered);
    }

    #[Test]
    public function converter_parses_nested_ul_inside_li(): void
    {
        $doc = (new Converter)->fromHtml('<ul><li>Outer<ul><li>Inner</li></ul></li></ul>');
        $list = $doc->section->body[0];

        self::assertInstanceOf(ListNode::class, $list);
        self::assertSame('Outer', $list->items[0]->children[0]->text);
        self::assertInstanceOf(ListNode::class, $list->items[0]->nestedList);
        self::assertSame('Inner', $list->items[0]->nestedList->items[0]->children[0]->text);
    }

    /**
     * @return list<string>
     */
    private static function zipEntries(string $bytes): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'docx-list-');
        file_put_contents($tmp, $bytes);
        try {
            $zip = new \ZipArchive;
            $zip->open($tmp);
            $names = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $names[] = $zip->getNameIndex($i);
            }
            $zip->close();
        } finally {
            @unlink($tmp);
        }

        return $names;
    }

    private static function extract(string $bytes, string $path): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'docx-list-');
        file_put_contents($tmp, $bytes);
        try {
            $zip = new \ZipArchive;
            $zip->open($tmp);
            $content = (string) $zip->getFromName($path);
            $zip->close();
        } finally {
            @unlink($tmp);
        }

        return $content;
    }
}
