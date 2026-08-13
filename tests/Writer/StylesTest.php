<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Tests\Writer;

use Dskripchenko\PhpDocx\Document;
use Dskripchenko\PhpDocx\Element\Paragraph;
use Dskripchenko\PhpDocx\Element\Run;
use Dskripchenko\PhpDocx\Section;
use Dskripchenko\PhpDocx\Style\Alignment;
use Dskripchenko\PhpDocx\Style\ParagraphStyle;
use Dskripchenko\PhpDocx\Style\RunStyle;
use Dskripchenko\PhpDocx\Style\StyleRegistry;
use Dskripchenko\PhpDocx\Writer\StylesXmlBuilder;
use Dskripchenko\PhpDocx\Writer\Word2007Writer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StylesTest extends TestCase
{
    #[Test]
    public function defaults_registers_headings_1_to_6_and_list_paragraph(): void
    {
        $r = StyleRegistry::defaults();
        $ids = array_keys($r->all());

        foreach (['Heading1', 'Heading2', 'Heading3', 'Heading4', 'Heading5', 'Heading6', 'ListParagraph'] as $id) {
            self::assertContains($id, $ids);
        }
    }

    #[Test]
    public function heading_validation_rejects_invalid_level(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new StyleRegistry)->heading(7, new RunStyle);
    }

    #[Test]
    public function caller_can_override_heading_style(): void
    {
        $custom = (new StyleRegistry)
            ->heading(1, new RunStyle(sizeHalfPoints: 60, color: 'ff00ff', bold: true),
                         new ParagraphStyle(alignment: Alignment::Center));

        $all = $custom->all();
        self::assertSame(60, $all['Heading1']['run']->sizeHalfPoints);
        self::assertSame('ff00ff', $all['Heading1']['run']->color);
        self::assertSame(Alignment::Center, $all['Heading1']['paragraph']->alignment);
    }

    #[Test]
    public function styles_xml_builder_emits_doc_defaults_and_styles(): void
    {
        $xml = (new StylesXmlBuilder(StyleRegistry::defaults()))->render();

        self::assertStringContainsString('<w:styles', $xml);
        self::assertStringContainsString('<w:docDefaults>', $xml);
        self::assertStringContainsString('<w:style w:type="paragraph" w:styleId="Heading1">', $xml);
        self::assertStringContainsString('<w:style w:type="paragraph" w:styleId="Heading6">', $xml);
        self::assertStringContainsString('<w:style w:type="paragraph" w:styleId="ListParagraph">', $xml);
        // <w:name> humanized
        self::assertStringContainsString('<w:name w:val="Heading 1"/>', $xml);
        self::assertStringContainsString('<w:name w:val="List Paragraph"/>', $xml);
    }

    #[Test]
    public function heading_style_includes_basedOn_normal_and_qFormat(): void
    {
        $xml = (new StylesXmlBuilder(StyleRegistry::defaults()))->render();

        self::assertStringContainsString('<w:basedOn w:val="Normal"/>', $xml);
        self::assertStringContainsString('<w:next w:val="Normal"/>', $xml);
        self::assertStringContainsString('<w:qFormat/>', $xml);
    }

    #[Test]
    public function writer_emits_styles_xml_part(): void
    {
        $doc = new Document(new Section(body: [new Paragraph([new Run('x')])]));
        $bytes = (new Word2007Writer)->write($doc);

        $tmp = tempnam(sys_get_temp_dir(), 'docx-styles-');
        file_put_contents($tmp, $bytes);
        try {
            $zip = new \ZipArchive;
            $zip->open($tmp);
            $names = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $names[] = $zip->getNameIndex($i);
            }
            $styles = (string) $zip->getFromName('word/styles.xml');
            $rels = (string) $zip->getFromName('word/_rels/document.xml.rels');
            $contentTypes = (string) $zip->getFromName('[Content_Types].xml');
            $zip->close();
        } finally {
            @unlink($tmp);
        }

        self::assertContains('word/styles.xml', $names);
        self::assertStringContainsString('Target="styles.xml"', $rels);
        self::assertStringContainsString('styles+xml', $contentTypes);
        // The defaults were applied
        self::assertStringContainsString('w:styleId="Heading1"', $styles);
    }

    #[Test]
    public function writer_uses_custom_registry_when_provided(): void
    {
        $registry = (new StyleRegistry)
            ->heading(1, new RunStyle(sizeHalfPoints: 100, color: 'ff0000', bold: true));

        $doc = new Document(new Section(body: []));
        $bytes = (new Word2007Writer($registry))->write($doc);

        $tmp = tempnam(sys_get_temp_dir(), 'docx-styles-');
        file_put_contents($tmp, $bytes);
        try {
            $zip = new \ZipArchive;
            $zip->open($tmp);
            $styles = (string) $zip->getFromName('word/styles.xml');
            $zip->close();
        } finally {
            @unlink($tmp);
        }

        self::assertStringContainsString('w:val="100"', $styles);
        self::assertStringContainsString('w:val="ff0000"', $styles);
        // Heading1 only — the caller passed no other headings
        self::assertStringNotContainsString('w:styleId="Heading2"', $styles);
    }
}
