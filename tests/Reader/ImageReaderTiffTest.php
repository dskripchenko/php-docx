<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Tests\Reader;

use Dskripchenko\PhpDocx\Element\ImageFormat;
use PHPUnit\Framework\TestCase;

/**
 * TIFF is what Word on macOS puts into the container by default.
 *
 * Such a format used to be dropped by the reader silently: the document arrived
 * without its logo and signature, and the only way to notice was comparing the
 * result against the original by eye. Found by comparing the print against a
 * reference document in printable.
 */
final class ImageReaderTiffTest extends TestCase
{
    public function test_tiff_is_a_known_format(): void
    {
        $this->assertSame('image/tiff', ImageFormat::Tiff->mimeType());
        $this->assertSame('tiff', ImageFormat::Tiff->extension());
    }

    public function test_tiff_is_not_web_safe(): void
    {
        // Word displays it, browsers and PDF engines do not. The consumer has to
        // learn that up front rather than from a blank spot in the document.
        $this->assertFalse(ImageFormat::Tiff->isWebSafe());
        $this->assertFalse(ImageFormat::Bmp->isWebSafe());
        $this->assertTrue(ImageFormat::Png->isWebSafe());
        $this->assertTrue(ImageFormat::Jpeg->isWebSafe());
    }
}
