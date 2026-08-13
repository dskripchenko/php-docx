<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx;

use Dskripchenko\PhpDocx\Style\CoreProperties;

/**
 * The root model of a DOCX document. In v1 there is one Section per document.
 *
 * The watermark is plain text, rendered in the centre of the header (rotated
 * -45°, semi-transparent grey). Null means none is added.
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
