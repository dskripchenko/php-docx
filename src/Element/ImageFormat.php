<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Element;

/**
 * Форматы картинок, поддерживаемые DOCX. PNG/JPEG — самые надёжные;
 * GIF/BMP — для legacy; TIFF — то, что кладёт Word на macOS. SVG лучше
 * rasterize'ить upstream.
 *
 * Незнакомый формат ридер отбрасывал молча: документ приходил без логотипа и
 * подписи, и понять это можно было только сравнив результат с оригиналом
 * глазами. Читать формат и отдавать его дальше — честнее: показать картинку
 * или отказаться от неё вслух решает потребитель.
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
     * Рисуют ли этот формат браузеры и PDF-движки.
     *
     * TIFF в DOCX жив и Word его показывает, но ни один браузер и почти ни
     * один PDF-эмиттер — нет. Потребителю нужно знать это заранее, чтобы
     * сконвертировать картинку, а не выяснять по пустому месту в документе.
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
