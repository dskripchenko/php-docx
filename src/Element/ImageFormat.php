<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Element;

/**
 * The image formats DOCX supports. PNG and JPEG are the most reliable; GIF and
 * BMP are legacy; TIFF is what Word on macOS produces. SVG is better
 * rasterized upstream.
 *
 * An unknown format used to be dropped by the reader silently: the document
 * arrived without its logo and signature, and the only way to notice was
 * comparing the result against the original by eye. Reading the format and
 * passing it on is more honest — whether to show the image or decline it out
 * loud is for the consumer to decide.
 */
enum ImageFormat: string
{
    case Png = 'png';
    case Jpeg = 'jpeg';
    case Gif = 'gif';
    case Bmp = 'bmp';
    case Tiff = 'tiff';

    public function mimeType(): string
    {
        return match ($this) {
            self::Png => 'image/png',
            self::Jpeg => 'image/jpeg',
            self::Gif => 'image/gif',
            self::Bmp => 'image/bmp',
            self::Tiff => 'image/tiff',
        };
    }

    /**
     * Whether browsers and PDF engines draw this format.
     *
     * TIFF is alive in DOCX and Word displays it, but no browser and almost no
     * PDF emitter does. The consumer needs to know that up front, so it can
     * convert the image instead of finding out from a blank spot in the
     * document.
     */
    public function isWebSafe(): bool
    {
        return match ($this) {
            self::Png, self::Jpeg, self::Gif => true,
            self::Bmp, self::Tiff => false,
        };
    }

    public function extension(): string
    {
        return match ($this) {
            self::Png => 'png',
            self::Jpeg => 'jpg',
            self::Gif => 'gif',
            self::Bmp => 'bmp',
            self::Tiff => 'tiff',
        };
    }
}
