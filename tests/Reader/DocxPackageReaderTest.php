<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Tests\Reader;

use Dskripchenko\PhpDocx\Exception\DocxException;
use Dskripchenko\PhpDocx\Html\Converter;
use Dskripchenko\PhpDocx\Reader\DocxPackage;
use Dskripchenko\PhpDocx\Reader\DocxPackageReader;
use Dskripchenko\PhpDocx\Reader\Relationship;
use Dskripchenko\PhpDocx\Writer\Word2007Writer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 1 tests — DocxPackageReader.
 *
 * The strategy is a writer round-trip: Writer\Word2007Writer creates a DOCX,
 * and the reader has to open it and take the parts apart correctly.
 */
final class DocxPackageReaderTest extends TestCase
{
    private const TINY_PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    #[Test]
    public function reads_minimal_document(): void
    {
        $bytes = $this->writeDocx('<p>Hello</p>');
        $pkg = (new DocxPackageReader)->read($bytes);

        self::assertInstanceOf(DocxPackage::class, $pkg);
        self::assertSame('word/document.xml', $pkg->documentPartPath);
        self::assertNotNull($pkg->documentXml);
        // A minimal writer DOCX always generates styles.xml.
        self::assertNotNull($pkg->stylesXml);
    }

    #[Test]
    public function fails_on_malformed_zip(): void
    {
        $this->expectException(DocxException::class);
        (new DocxPackageReader)->read('not a zip at all');
    }

    #[Test]
    public function fails_on_zip_without_content_types(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'badzip-');
        $zip = new \ZipArchive;
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $zip->addFromString('hello.txt', 'world');
        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        $this->expectException(DocxException::class);
        (new DocxPackageReader)->read($bytes);
    }

    #[Test]
    public function discovers_main_document_via_root_rels(): void
    {
        $bytes = $this->writeDocx('<p>Hi</p>');
        $pkg = (new DocxPackageReader)->read($bytes);

        $rels = $pkg->documentRelationships();
        self::assertNotEmpty($rels);
        // The writer always adds the styles.xml relationship.
        $stylesRels = array_filter($rels, fn (Relationship $r) => $r->type === Relationship::TYPE_STYLES);
        self::assertNotEmpty($stylesRels);
    }

    #[Test]
    public function extracts_image_media_files(): void
    {
        $bytes = $this->writeDocx('<p>Pic: <img src="'.self::TINY_PNG.'" width="10" height="10" alt="X"/></p>');
        $pkg = (new DocxPackageReader)->read($bytes);

        self::assertCount(1, $pkg->media);
        $firstMedia = array_key_first($pkg->media);
        self::assertStringStartsWith('word/media/image', $firstMedia);
        self::assertNotSame('', $pkg->mediaBytes($firstMedia));

        // The image relationship is registered for the document.
        $imageRels = array_filter(
            $pkg->documentRelationships(),
            fn (Relationship $r) => $r->type === Relationship::TYPE_IMAGE,
        );
        self::assertCount(1, $imageRels);
    }

    #[Test]
    public function reads_numbering_when_lists_used(): void
    {
        $bytes = $this->writeDocx('<ul><li>a</li><li>b</li></ul>');
        $pkg = (new DocxPackageReader)->read($bytes);

        self::assertNotNull($pkg->numberingXml);
    }

    #[Test]
    public function does_not_read_numbering_when_no_lists(): void
    {
        $bytes = $this->writeDocx('<p>plain</p>');
        $pkg = (new DocxPackageReader)->read($bytes);

        self::assertNull($pkg->numberingXml);
    }

    #[Test]
    public function reads_header_and_footer_parts(): void
    {
        $writer = new Word2007Writer;
        $doc = (new Converter)->fromHtml(
            body: '<p>Body</p>',
            header: '<p>Hdr</p>',
            footer: '<p>Ftr</p>',
        );
        $bytes = $writer->write($doc);
        $pkg = (new DocxPackageReader)->read($bytes);

        self::assertCount(1, $pkg->headers);
        self::assertCount(1, $pkg->footers);
        $headerPath = array_key_first($pkg->headers);
        self::assertStringStartsWith('word/header', $headerPath);
    }

    #[Test]
    public function resolves_relationship_target_to_zip_path(): void
    {
        $bytes = $this->writeDocx('<p>Pic <img src="'.self::TINY_PNG.'" width="5" height="5"/></p>');
        $pkg = (new DocxPackageReader)->read($bytes);

        $imageRel = null;
        foreach ($pkg->documentRelationships() as $r) {
            if ($r->type === Relationship::TYPE_IMAGE) {
                $imageRel = $r;
                break;
            }
        }
        self::assertNotNull($imageRel);
        // The target is relative (media/image1.png).
        $abs = $pkg->resolveMediaPath($pkg->documentPartPath, $imageRel->target);
        self::assertSame('word/media/image1.png', $abs);
    }

    #[Test]
    public function resolve_document_rel_throws_for_unknown_rId(): void
    {
        $bytes = $this->writeDocx('<p>plain</p>');
        $pkg = (new DocxPackageReader)->read($bytes);

        $this->expectException(DocxException::class);
        $pkg->resolveDocumentRel('rId999');
    }

    #[Test]
    public function reads_content_type_defaults_and_overrides(): void
    {
        $bytes = $this->writeDocx('<p>x</p>');
        $pkg = (new DocxPackageReader)->read($bytes);

        // The writer always emits the rels/xml defaults.
        self::assertSame(
            'application/vnd.openxmlformats-package.relationships+xml',
            $pkg->defaultContentTypes['rels'],
        );
        // The override for document.xml is mandatory.
        self::assertArrayHasKey('word/document.xml', $pkg->overrideContentTypes);
        self::assertSame(
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml',
            $pkg->overrideContentTypes['word/document.xml'],
        );
    }

    #[Test]
    public function hyperlink_rel_marked_external(): void
    {
        $bytes = $this->writeDocx('<p><a href="https://example.com">x</a></p>');
        $pkg = (new DocxPackageReader)->read($bytes);

        $hyperlinkRels = array_filter(
            $pkg->documentRelationships(),
            fn (Relationship $r) => $r->type === Relationship::TYPE_HYPERLINK,
        );
        self::assertCount(1, $hyperlinkRels);
        /** @var Relationship $rel */
        $rel = array_values($hyperlinkRels)[0];
        self::assertTrue($rel->isExternal());
        self::assertSame('https://example.com', $rel->target);
    }

    private function writeDocx(string $bodyHtml): string
    {
        return (new Word2007Writer)->write((new Converter)->fromHtml($bodyHtml));
    }
}
