<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Tests\Writer;

use Dskripchenko\PhpDocx\Document;
use Dskripchenko\PhpDocx\Element\Image;
use Dskripchenko\PhpDocx\Element\ImageFormat;
use Dskripchenko\PhpDocx\Element\Paragraph;
use Dskripchenko\PhpDocx\Element\Run;
use Dskripchenko\PhpDocx\Reader\DocxReader;
use Dskripchenko\PhpDocx\Section;
use Dskripchenko\PhpDocx\Style\HorizontalAnchor;
use Dskripchenko\PhpDocx\Style\ImagePosition;
use Dskripchenko\PhpDocx\Style\ImageWrap;
use Dskripchenko\PhpDocx\Style\PositionAlign;
use Dskripchenko\PhpDocx\Style\VerticalAnchor;
use Dskripchenko\PhpDocx\Writer\Word2007Writer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** A picture with a position floats (`<wp:anchor>`) instead of sitting in the line. */
final class FloatingImageTest extends TestCase
{
    // 1×1 transparent PNG
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private static function image(?ImagePosition $position = null, int $offsetYEmu = 0): Image
    {
        return new Image((string) base64_decode(self::PNG, true), ImageFormat::Png, 914400, 457200, 'logo', $offsetYEmu, $position);
    }

    /** @return array<string, string> */
    private static function parts(Document $doc): array
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'docx-float-');
        file_put_contents($tmp, (new Word2007Writer)->write($doc));
        $zip = new \ZipArchive;
        $zip->open($tmp);
        $out = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $out[(string) $zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        unlink($tmp);

        return $out;
    }

    private static function xpath(string $xml): \DOMXPath
    {
        $dom = new \DOMDocument;
        $dom->loadXML($xml);
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $xp->registerNamespace('wp', 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing');
        $xp->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');

        return $xp;
    }

    private static function body(Image $img): \DOMXPath
    {
        return self::xpath(self::parts(new Document(new Section([$img, new Paragraph([new Run('text')])])))['word/document.xml']);
    }

    #[Test]
    public function without_a_position_a_picture_is_inline(): void
    {
        $xp = self::body(self::image());
        self::assertSame(1.0, $xp->evaluate('count(//wp:inline)'));
        self::assertSame(0.0, $xp->evaluate('count(//wp:anchor)'));
    }

    #[Test]
    public function a_picture_behind_the_text_at_a_page_offset(): void
    {
        $xp = self::body(self::image(new ImagePosition(
            horizontalFrom: HorizontalAnchor::Page,
            xEmu: 720000,
            verticalFrom: VerticalAnchor::Page,
            yEmu: 360000,
            behindText: true,
            zOrder: 3,
        )));
        self::assertSame(0.0, $xp->evaluate('count(//wp:inline)'));
        self::assertSame('1', $xp->evaluate('string(//wp:anchor/@behindDoc)'));
        self::assertSame('3', $xp->evaluate('string(//wp:anchor/@relativeHeight)'));
        self::assertSame('page', $xp->evaluate('string(//wp:anchor/wp:positionH/@relativeFrom)'));
        self::assertSame('720000', $xp->evaluate('string(//wp:anchor/wp:positionH/wp:posOffset)'));
        self::assertSame('page', $xp->evaluate('string(//wp:anchor/wp:positionV/@relativeFrom)'));
        self::assertSame('360000', $xp->evaluate('string(//wp:anchor/wp:positionV/wp:posOffset)'));
        self::assertSame(1.0, $xp->evaluate('count(//wp:anchor/wp:wrapNone)'));
        self::assertSame('914400', $xp->evaluate('string(//wp:anchor/wp:extent/@cx)'));
        self::assertSame('logo', $xp->evaluate('string(//wp:anchor/wp:docPr/@descr)'));
        self::assertSame(1.0, $xp->evaluate('count(//wp:anchor/a:graphic)'));
        // CT_Anchor is a sequence: positions, then extent, then wrap, then docPr.
        self::assertSame(
            ['simplePos', 'positionH', 'positionV', 'extent', 'effectExtent', 'wrapNone', 'docPr', 'cNvGraphicFramePr', 'graphic'],
            array_map(static fn (\DOMElement $e): string => $e->localName, iterator_to_array($xp->query('//wp:anchor/*'))),
        );
    }

    #[Test]
    public function alignment_instead_of_offsets_and_wrapping(): void
    {
        $xp = self::body(self::image(new ImagePosition(
            horizontalFrom: HorizontalAnchor::Margin,
            horizontalAlign: PositionAlign::Center,
            verticalFrom: VerticalAnchor::Margin,
            verticalAlign: PositionAlign::Top,
            wrap: ImageWrap::Square,
        )));
        self::assertSame('center', $xp->evaluate('string(//wp:positionH/wp:align)'));
        self::assertSame('top', $xp->evaluate('string(//wp:positionV/wp:align)'));
        self::assertSame(0.0, $xp->evaluate('count(//wp:posOffset)'));
        self::assertSame('bothSides', $xp->evaluate('string(//wp:wrapSquare/@wrapText)'));
        self::assertSame('0', $xp->evaluate('string(//wp:anchor/@behindDoc)'));

        $xp = self::body(self::image(new ImagePosition(wrap: ImageWrap::TopAndBottom)));
        self::assertSame(1.0, $xp->evaluate('count(//wp:wrapTopAndBottom)'));
    }

    #[Test]
    public function an_offset_y_alone_floats_from_its_paragraph(): void
    {
        $xp = self::body(self::image(offsetYEmu: -254000));
        self::assertSame('paragraph', $xp->evaluate('string(//wp:positionV/@relativeFrom)'));
        self::assertSame('-254000', $xp->evaluate('string(//wp:positionV/wp:posOffset)'));
        self::assertSame('column', $xp->evaluate('string(//wp:positionH/@relativeFrom)'));
    }

    #[Test]
    public function a_picture_in_the_header_positioned_on_the_page_repeats_on_every_page(): void
    {
        $parts = self::parts(new Document(new Section(
            [new Paragraph([new Run('body')])],
            header: [new Paragraph([self::image(new ImagePosition(horizontalFrom: HorizontalAnchor::Page, verticalFrom: VerticalAnchor::Page, behindText: true))])],
        )));
        $xp = self::xpath($parts['word/header1.xml']);
        self::assertSame(1.0, $xp->evaluate('count(//wp:anchor)'));
        self::assertStringContainsString('image', $parts['word/_rels/header1.xml.rels']);
    }

    #[Test]
    public function an_alignment_on_the_wrong_axis_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ImagePosition(horizontalAlign: PositionAlign::Top);
    }

    #[Test]
    public function the_position_is_read_back(): void
    {
        $position = new ImagePosition(
            horizontalFrom: HorizontalAnchor::Page,
            xEmu: 720000,
            verticalFrom: VerticalAnchor::Margin,
            verticalAlign: PositionAlign::Bottom,
            behindText: true,
            wrap: ImageWrap::TopAndBottom,
            zOrder: 7,
        );
        $bytes = (new Word2007Writer)->write(new Document(new Section([new Paragraph([self::image($position)])])));
        $read = (new DocxReader)->read($bytes)->section->body[0];
        self::assertInstanceOf(Paragraph::class, $read);
        $img = $read->children[0];
        self::assertInstanceOf(Image::class, $img);
        self::assertEquals($position, $img->position);
    }

    #[Test]
    public function the_builder_takes_a_position(): void
    {
        $position = new ImagePosition(horizontalFrom: HorizontalAnchor::Page, verticalFrom: VerticalAnchor::Page, behindText: true);
        $png = (string) base64_decode(self::PNG, true);
        $tmp = (string) tempnam(sys_get_temp_dir(), 'png');
        file_put_contents($tmp, $png);
        try {
            $doc = \Dskripchenko\PhpDocx\Build\DocumentBuilder::new()
                ->paragraph(fn ($p) => $p
                    ->imageFromBytes($png, ImageFormat::Png, 10, 10, position: $position)
                    ->imageFromDataUrl('data:image/png;base64,'.self::PNG, position: $position)
                    ->imageFromFile($tmp, position: $position))
                ->build();
        } finally {
            unlink($tmp);
        }
        $p = $doc->section->body[0];
        self::assertInstanceOf(Paragraph::class, $p);
        self::assertCount(3, $p->children);
        foreach ($p->children as $img) {
            self::assertInstanceOf(Image::class, $img);
            self::assertSame($position, $img->position);
        }
    }
}
