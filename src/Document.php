<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx;

use Dskripchenko\PhpDocx\Style\CoreProperties;

/**
 * The root model of a DOCX document: one Section, or several when the page
 * setup or the headers change part way through (`$followingSections`).
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
        /**
         * Sections after the first, in order. Each starts as its `start` says
         * (on a new page by default) and has its own page setup; a section
         * with no header or footer of a kind shows the previous section's, as
         * in Word.
         *
         * @var list<Section>
         */
        public array $followingSections = [],
    ) {}

    /**
     * Every section, the first one included.
     *
     * @return non-empty-list<Section>
     */
    public function sections(): array
    {
        return [$this->section, ...$this->followingSections];
    }
}
