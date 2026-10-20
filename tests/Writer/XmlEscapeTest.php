<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Tests\Writer;

use Dskripchenko\PhpDocx\Document;
use Dskripchenko\PhpDocx\Element\Hyperlink;
use Dskripchenko\PhpDocx\Element\Paragraph;
use Dskripchenko\PhpDocx\Element\Run;
use Dskripchenko\PhpDocx\Section;
use Dskripchenko\PhpDocx\Writer\Word2007Writer;
use Dskripchenko\PhpDocx\Writer\XmlEscape;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class XmlEscapeTest extends TestCase
{
    #[Test]
    public function it_escapes_the_markup_characters(): void
    {
        self::assertSame('A &amp; B &lt; C &gt; &quot;D&quot; &apos;E&apos;', XmlEscape::text('A & B < C > "D" \'E\''));
        self::assertSame('&amp;&quot;', XmlEscape::attr('&"'));
    }

    #[Test]
    public function invalid_utf8_keeps_the_valid_part(): void
    {
        // Before, htmlspecialchars returned '' and the whole run was lost.
        self::assertSame("caf\u{FFFD} au lait", XmlEscape::text("caf\xE9 au lait"));
        self::assertSame("x\u{FFFD}y", XmlEscape::attr("x\xFFy"));
    }

    #[Test]
    public function characters_xml_does_not_allow_are_removed(): void
    {
        self::assertSame('ab', XmlEscape::text("a\x01\x0B\x1Fb"));
        self::assertSame('ab', XmlEscape::text("a\u{FFFE}\u{FFFF}b"));
        self::assertSame('ab', XmlEscape::attr("a\x00b"));
    }

    #[Test]
    public function allowed_whitespace_and_other_characters_are_kept(): void
    {
        self::assertSame("a\tb\nc\rd", XmlEscape::text("a\tb\nc\rd"));
        self::assertSame("Привет 你好 \u{1F600} \u{FFFD}", XmlEscape::text("Привет 你好 \u{1F600} \u{FFFD}"));
    }

    #[Test]
    public function a_control_character_in_run_text_gives_a_well_formed_document(): void
    {
        $bytes = (new Word2007Writer)->write(new Document(new Section([
            new Paragraph([
                new Run("before\x01after"),
                new Run("bad \xC3( utf8"),
                new Hyperlink("https://example.com/?q=\x02", [new Run('link')]),
            ]),
        ])));

        foreach (['word/document.xml', 'word/_rels/document.xml.rels'] as $name) {
            $dom = new \DOMDocument;
            self::assertTrue(@$dom->loadXML(self::part($bytes, $name)), $name.' is not well-formed');
        }
        $xml = self::part($bytes, 'word/document.xml');
        self::assertStringContainsString('>beforeafter<', $xml);
        self::assertStringContainsString("bad \u{FFFD}( utf8", $xml);
    }

    private static function part(string $bytes, string $name): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'docx-test-');
        self::assertIsString($tmp);
        file_put_contents($tmp, $bytes);
        $zip = new \ZipArchive;
        $zip->open($tmp);
        $xml = $zip->getFromName($name);
        $zip->close();
        unlink($tmp);
        self::assertIsString($xml);

        return $xml;
    }
}
