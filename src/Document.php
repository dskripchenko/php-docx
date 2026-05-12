<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx;

/**
 * Корневая модель DOCX-документа. v1 — одна Section на документ.
 *
 * Watermark — простой текст, отрендерится по центру header'а (rotate -45°,
 * серым полупрозрачным). Если null — не добавляется.
 */
final readonly class Document
{
    public function __construct(
        public Section $section,
        public ?string $watermarkText = null,
    ) {}
}
