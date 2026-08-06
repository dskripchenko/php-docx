<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx;

use Dskripchenko\PhpDocx\Style\CoreProperties;

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
        /**
         * Core properties (`docProps/core.xml`): what Word shows under
         * File → Info. Null means the part is not written at all — an
         * empty properties part is worse than none, it just adds noise.
         */
        public ?CoreProperties $coreProperties = null,
    ) {}
}
