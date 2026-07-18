<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Tests\Reader;

use Dskripchenko\PhpDocx\Element\Image;
use Dskripchenko\PhpDocx\Element\ImageFormat;
use Dskripchenko\PhpDocx\Element\Paragraph;
use Dskripchenko\PhpDocx\Html\Converter;
use Dskripchenko\PhpDocx\Reader\BodyReader;
use Dskripchenko\PhpDocx\Reader\DocxPackageReader;
use Dskripchenko\PhpDocx\Reader\ImageReader;
use Dskripchenko\PhpDocx\Reader\NumberingReader;
use Dskripchenko\PhpDocx\Reader\OoxmlNs;
use Dskripchenko\PhpDocx\Reader\StylesResolver;
use Dskripchenko\PhpDocx\Writer\Word2007Writer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ImageReaderTest extends TestCase
{
    private const TINY_PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private const TINY_JPEG = 'data:image/jpeg;base64,/9j/4AAQSkZJRgABAQEAYABgAAD//gA7Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2NjIpLCBxdWFsaXR5ID0gOTAK/9sAQwADAgIDAgIDAwMDBAMDBAUIBQUEBAUKBwcGCAwKDAwLCgsLDQ4SEA0OEQ4LCxAWEBETFBUVFQwPFxgWFBgSFBUU/9sAQwEDBAQFBAUJBQUJFA0LDRQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQU/8AAEQgAAQABAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8A4Wivp9D//9k=';

    #[Test]
    public function reads_inline_png_image(): void
    {
        $bytes = $this->writeDocx('<p>Img: <img src="'.self::TINY_PNG.'" width="20" height="20" alt="logo"/></p>');
        $pkg = (new DocxPackageReader)->read($bytes);
        $imageReader = new ImageReader($pkg, $pkg->documentPartPath);
        $reader = new BodyReader(
            new StylesResolver($pkg->stylesXml),
            (new NumberingReader)->read($pkg->numberingXml),
            $imageReader,
        );
        $body = $pkg->documentXml->getElementsByTagNameNS(OoxmlNs::W, 'body')->item(0);
        self::assertInstanceOf(\DOMElement::class, $body);

        $blocks = $reader->read($body);
        $images = $this->findImages($blocks);
        self::assertCount(1, $images);
        /** @var Image $img */
        $img = $images[0];
        self::assertSame(ImageFormat::Png, $img->format);
        self::assertSame('logo', $img->altText);
        // 20px → 20 * 9525 = 190500 EMU
        self::assertSame(190500, $img->widthEmu);
        self::assertSame(190500, $img->heightEmu);
        // Binary не пустой и начинается с PNG-magic.
        self::assertStringStartsWith("\x89PNG", $img->binary);
    }

    #[Test]
    public function reads_jpeg_image(): void
    {
        $bytes = $this->writeDocx('<p><img src="'.self::TINY_JPEG.'" width="10" height="10" alt="photo"/></p>');
        $pkg = (new DocxPackageReader)->read($bytes);
        $imageReader = new ImageReader($pkg, $pkg->documentPartPath);
        $reader = new BodyReader(
            new StylesResolver($pkg->stylesXml),
            (new NumberingReader)->read($pkg->numberingXml),
            $imageReader,
        );
        $body = $pkg->documentXml->getElementsByTagNameNS(OoxmlNs::W, 'body')->item(0);
        self::assertInstanceOf(\DOMElement::class, $body);

        $images = $this->findImages($reader->read($body));
        self::assertCount(1, $images);
        self::assertSame(ImageFormat::Jpeg, $images[0]->format);
    }

    #[Test]
    public function image_without_imageReader_omitted(): void
    {
        $bytes = $this->writeDocx('<p><img src="'.self::TINY_PNG.'" width="10" height="10"/></p>');
        $pkg = (new DocxPackageReader)->read($bytes);
        $reader = new BodyReader(
            new StylesResolver($pkg->stylesXml),
            (new NumberingReader)->read($pkg->numberingXml),
            // imageReader: null
        );
        $body = $pkg->documentXml->getElementsByTagNameNS(OoxmlNs::W, 'body')->item(0);
        self::assertInstanceOf(\DOMElement::class, $body);

        $images = $this->findImages($reader->read($body));
        self::assertCount(0, $images);
    }

    #[Test]
    public function multiple_images_resolved_independently(): void
    {
        $bytes = $this->writeDocx(
            '<p><img src="'.self::TINY_PNG.'" width="10" height="10" alt="a"/></p>'
            .'<p><img src="'.self::TINY_PNG.'" width="20" height="20" alt="b"/></p>'
        );
        $pkg = (new DocxPackageReader)->read($bytes);
        $imageReader = new ImageReader($pkg, $pkg->documentPartPath);
        $reader = new BodyReader(
            new StylesResolver($pkg->stylesXml),
            (new NumberingReader)->read($pkg->numberingXml),
            $imageReader,
        );
        $body = $pkg->documentXml->getElementsByTagNameNS(OoxmlNs::W, 'body')->item(0);
        self::assertInstanceOf(\DOMElement::class, $body);

        $images = $this->findImages($reader->read($body));
        self::assertCount(2, $images);
        self::assertSame('a', $images[0]->altText);
        self::assertSame('b', $images[1]->altText);
        self::assertSame(95250, $images[0]->widthEmu);  // 10*9525
        self::assertSame(190500, $images[1]->widthEmu); // 20*9525
    }

    #[Test]
    public function image_with_no_alt_text(): void
    {
        $bytes = $this->writeDocx('<p><img src="'.self::TINY_PNG.'" width="10" height="10"/></p>');
        $pkg = (new DocxPackageReader)->read($bytes);
        $imageReader = new ImageReader($pkg, $pkg->documentPartPath);
        $reader = new BodyReader(
            new StylesResolver($pkg->stylesXml),
            (new NumberingReader)->read($pkg->numberingXml),
            $imageReader,
        );
        $body = $pkg->documentXml->getElementsByTagNameNS(OoxmlNs::W, 'body')->item(0);
        self::assertInstanceOf(\DOMElement::class, $body);

        $images = $this->findImages($reader->read($body));
        self::assertCount(1, $images);
        // Writer пишет "image" если alt отсутствует — это валидно
        self::assertNotNull($images[0]->altText);
    }

    #[Test]
    public function image_binary_matches_original_png_bytes(): void
    {
        $bytes = $this->writeDocx('<p><img src="'.self::TINY_PNG.'" width="10" height="10"/></p>');
        $pkg = (new DocxPackageReader)->read($bytes);
        $imageReader = new ImageReader($pkg, $pkg->documentPartPath);
        $reader = new BodyReader(
            new StylesResolver($pkg->stylesXml),
            (new NumberingReader)->read($pkg->numberingXml),
            $imageReader,
        );
        $body = $pkg->documentXml->getElementsByTagNameNS(OoxmlNs::W, 'body')->item(0);
        self::assertInstanceOf(\DOMElement::class, $body);

        $images = $this->findImages($reader->read($body));
        $originalBytes = base64_decode(substr(self::TINY_PNG, strlen('data:image/png;base64,')));
        self::assertSame($originalBytes, $images[0]->binary);
    }

    /**
     * @param  list<\Dskripchenko\PhpDocx\Element\BlockElement>  $blocks
     * @return list<Image>
     */
    private function findImages(array $blocks): array
    {
        $out = [];
        foreach ($blocks as $b) {
            if (! $b instanceof Paragraph) {
                continue;
            }
            foreach ($b->children as $c) {
                if ($c instanceof Image) {
                    $out[] = $c;
                }
            }
        }

        return $out;
    }

    private function writeDocx(string $bodyHtml): string
    {
        return (new Word2007Writer)->write((new Converter)->fromHtml($bodyHtml));
    }
}
