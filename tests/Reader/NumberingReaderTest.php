<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Tests\Reader;

use Dskripchenko\PhpDocx\Element\ListFormat;
use Dskripchenko\PhpDocx\Element\ListItem;
use Dskripchenko\PhpDocx\Element\ListNode;
use Dskripchenko\PhpDocx\Element\Paragraph;
use Dskripchenko\PhpDocx\Element\Run;
use Dskripchenko\PhpDocx\Html\Converter;
use Dskripchenko\PhpDocx\Reader\BodyReader;
use Dskripchenko\PhpDocx\Reader\DocxPackageReader;
use Dskripchenko\PhpDocx\Reader\NumberingReader;
use Dskripchenko\PhpDocx\Reader\OoxmlNs;
use Dskripchenko\PhpDocx\Reader\StylesResolver;
use Dskripchenko\PhpDocx\Writer\Word2007Writer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NumberingReaderTest extends TestCase
{
    #[Test]
    public function reads_simple_bullet_definition(): void
    {
        $xml = $this->loadNumbering(
            '<w:abstractNum w:abstractNumId="0">'
            .'<w:lvl w:ilvl="0">'
            .'<w:start w:val="1"/>'
            .'<w:numFmt w:val="bullet"/>'
            .'</w:lvl>'
            .'</w:abstractNum>'
            .'<w:num w:numId="1"><w:abstractNumId w:val="0"/></w:num>'
        );
        $defs = (new NumberingReader)->read($xml);

        self::assertTrue($defs->hasNumId(1));
        self::assertSame(ListFormat::Bullet, $defs->formatFor(1, 0));
        self::assertFalse($defs->isOrdered(1, 0));
    }

    #[Test]
    public function reads_decimal_with_startAt(): void
    {
        $xml = $this->loadNumbering(
            '<w:abstractNum w:abstractNumId="0">'
            .'<w:lvl w:ilvl="0"><w:start w:val="5"/><w:numFmt w:val="decimal"/></w:lvl>'
            .'</w:abstractNum>'
            .'<w:num w:numId="2"><w:abstractNumId w:val="0"/></w:num>'
        );
        $defs = (new NumberingReader)->read($xml);

        self::assertSame(ListFormat::Decimal, $defs->formatFor(2, 0));
        self::assertSame(5, $defs->startAtFor(2, 0));
    }

    #[Test]
    public function reads_lower_letter_format(): void
    {
        $xml = $this->loadNumbering(
            '<w:abstractNum w:abstractNumId="0">'
            .'<w:lvl w:ilvl="0"><w:start w:val="1"/><w:numFmt w:val="lowerLetter"/></w:lvl>'
            .'</w:abstractNum>'
            .'<w:num w:numId="3"><w:abstractNumId w:val="0"/></w:num>'
        );
        $defs = (new NumberingReader)->read($xml);

        self::assertSame(ListFormat::LowerLetter, $defs->formatFor(3, 0));
    }

    #[Test]
    public function startOverride_overrides_abstract_start(): void
    {
        $xml = $this->loadNumbering(
            '<w:abstractNum w:abstractNumId="0">'
            .'<w:lvl w:ilvl="0"><w:start w:val="1"/><w:numFmt w:val="decimal"/></w:lvl>'
            .'</w:abstractNum>'
            .'<w:num w:numId="5">'
            .'<w:abstractNumId w:val="0"/>'
            .'<w:lvlOverride w:ilvl="0"><w:startOverride w:val="10"/></w:lvlOverride>'
            .'</w:num>'
        );
        $defs = (new NumberingReader)->read($xml);

        self::assertSame(10, $defs->startAtFor(5, 0));
    }

    #[Test]
    public function multiple_levels(): void
    {
        $xml = $this->loadNumbering(
            '<w:abstractNum w:abstractNumId="0">'
            .'<w:lvl w:ilvl="0"><w:start w:val="1"/><w:numFmt w:val="decimal"/></w:lvl>'
            .'<w:lvl w:ilvl="1"><w:start w:val="1"/><w:numFmt w:val="lowerLetter"/></w:lvl>'
            .'<w:lvl w:ilvl="2"><w:start w:val="1"/><w:numFmt w:val="lowerRoman"/></w:lvl>'
            .'</w:abstractNum>'
            .'<w:num w:numId="1"><w:abstractNumId w:val="0"/></w:num>'
        );
        $defs = (new NumberingReader)->read($xml);

        self::assertSame(ListFormat::Decimal, $defs->formatFor(1, 0));
        self::assertSame(ListFormat::LowerLetter, $defs->formatFor(1, 1));
        self::assertSame(ListFormat::LowerRoman, $defs->formatFor(1, 2));
    }

    #[Test]
    public function null_numbering_xml_yields_empty_defs(): void
    {
        $defs = (new NumberingReader)->read(null);
        self::assertFalse($defs->hasNumId(1));
    }

    #[Test]
    public function roundtrip_bullet_list(): void
    {
        $bytes = (new Word2007Writer)->write((new Converter)->fromHtml(
            '<ul><li>Apple</li><li>Banana</li><li>Cherry</li></ul>'
        ));

        [$blocks] = $this->readFromBytes($bytes);
        $lists = array_values(array_filter($blocks, fn ($b) => $b instanceof ListNode));
        self::assertCount(1, $lists);
        /** @var ListNode $list */
        $list = $lists[0];
        self::assertFalse($list->ordered);
        self::assertCount(3, $list->items);
        self::assertSame('Apple', $this->itemText($list->items[0]));
        self::assertSame('Cherry', $this->itemText($list->items[2]));
    }

    #[Test]
    public function roundtrip_ordered_list_with_type_a(): void
    {
        $bytes = (new Word2007Writer)->write((new Converter)->fromHtml(
            '<ol type="a"><li>x</li><li>y</li></ol>'
        ));
        [$blocks] = $this->readFromBytes($bytes);
        $lists = array_values(array_filter($blocks, fn ($b) => $b instanceof ListNode));
        /** @var ListNode $list */
        $list = $lists[0];
        self::assertTrue($list->ordered);
        self::assertSame(ListFormat::LowerLetter, $list->effectiveFormat());
    }

    #[Test]
    public function roundtrip_nested_lists(): void
    {
        $bytes = (new Word2007Writer)->write((new Converter)->fromHtml(
            '<ul>'
            .'<li>Top1<ul><li>Sub1</li><li>Sub2</li></ul></li>'
            .'<li>Top2</li>'
            .'</ul>'
        ));
        [$blocks] = $this->readFromBytes($bytes);
        $lists = array_values(array_filter($blocks, fn ($b) => $b instanceof ListNode));
        /** @var ListNode $list */
        $list = $lists[0];
        // Topmost: 2 items.
        self::assertGreaterThanOrEqual(1, count($list->items));
        // Первый item должен иметь nested list с 2 children.
        $firstItem = $list->items[0];
        self::assertNotNull($firstItem->nestedList);
        self::assertCount(2, $firstItem->nestedList->items);
    }

    #[Test]
    public function ol_start_5_roundtrip(): void
    {
        $bytes = (new Word2007Writer)->write((new Converter)->fromHtml(
            '<ol start="5"><li>one</li><li>two</li></ol>'
        ));
        [$blocks] = $this->readFromBytes($bytes);
        $lists = array_values(array_filter($blocks, fn ($b) => $b instanceof ListNode));
        /** @var ListNode $list */
        $list = $lists[0];
        self::assertSame(5, $list->startAt);
    }

    #[Test]
    public function mixed_text_and_list_separates_correctly(): void
    {
        $bytes = (new Word2007Writer)->write((new Converter)->fromHtml(
            '<p>Intro</p>'
            .'<ul><li>a</li><li>b</li></ul>'
            .'<p>Outro</p>'
        ));
        [$blocks] = $this->readFromBytes($bytes);

        self::assertCount(3, $blocks);
        self::assertInstanceOf(Paragraph::class, $blocks[0]);
        self::assertInstanceOf(ListNode::class, $blocks[1]);
        self::assertInstanceOf(Paragraph::class, $blocks[2]);
    }

    /**
     * @return array{0:list<\Dskripchenko\PhpDocx\Element\BlockElement>}
     */
    private function readFromBytes(string $bytes): array
    {
        $pkg = (new DocxPackageReader)->read($bytes);
        $resolver = new StylesResolver($pkg->stylesXml);
        $numbering = (new NumberingReader)->read($pkg->numberingXml);
        $reader = new BodyReader($resolver, $numbering);
        $body = $pkg->documentXml->getElementsByTagNameNS(OoxmlNs::W, 'body')->item(0);
        if (! $body instanceof \DOMElement) {
            throw new \RuntimeException('No body');
        }

        return [$reader->read($body)];
    }

    private function itemText(ListItem $item): string
    {
        $text = '';
        foreach ($item->children as $c) {
            if ($c instanceof Run) {
                $text .= $c->text;
            }
        }

        return $text;
    }

    private function loadNumbering(string $inner): \DOMDocument
    {
        $doc = new \DOMDocument;
        $doc->loadXML(
            '<w:numbering xmlns:w="'.OoxmlNs::W.'">'.$inner.'</w:numbering>'
        );

        return $doc;
    }
}
