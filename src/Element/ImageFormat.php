<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Element;

/**
 * Форматы картинок, поддерживаемые DOCX. PNG/JPEG — самые надёжные;
 * GIF/BMP — для legacy. SVG лучше rasterize'ить upstream.
 */
enum ImageFormat: string
{
    case Png = 'png';
    case Jpeg = 'jpeg';
    case Gif = 'gif';
    case Bmp = 'bmp';

    public function mimeType(): string
    {
        return match ($this) {
            self::Png => 'image/png',
            self::Jpeg => 'image/jpeg',
            self::Gif => 'image/gif',
            self::Bmp => 'image/bmp',
        };
    }

    public function extension(): string
    {
        return match ($this) {
            self::Png => 'png',
            self::Jpeg => 'jpg',
            self::Gif => 'gif',
            self::Bmp => 'bmp',
        };
    }
}
