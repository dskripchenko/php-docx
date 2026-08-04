<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Tests\Reader;

use Dskripchenko\PhpDocx\Element\ImageFormat;
use PHPUnit\Framework\TestCase;

/**
 * TIFF — то, что Word на macOS кладёт в контейнер по умолчанию.
 *
 * Раньше такой формат ридер отбрасывал молча: документ приходил без логотипа и
 * подписи, а понять это можно было только сравнив результат с оригиналом
 * глазами. Найдено сравнением печати с эталонным документом в printable.
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
        // Word его показывает, браузеры и PDF-движки — нет. Потребитель должен
        // узнать это заранее, а не по пустому месту в документе.
        $this->assertFalse(ImageFormat::Tiff->isWebSafe());
        $this->assertFalse(ImageFormat::Bmp->isWebSafe());
        $this->assertTrue(ImageFormat::Png->isWebSafe());
        $this->assertTrue(ImageFormat::Jpeg->isWebSafe());
    }
}
