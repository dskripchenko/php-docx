<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Reader;

/**
 * A detected variable — the IR between the reader and the importer on the
 * application side (printable).
 *
 * The importer decides on its own how to convert this IR into its placeholder
 * syntax (`{{name}}`, `<var data-name>`, etc.).
 */
final readonly class DetectedVariable
{
    public function __construct(
        public string $name,
        public VariableSource $source,
        /** The plain-text placeholder as it occurs in the document (for replacement). */
        public string $placeholder,
        /** The sample value as rendered by Word (for the preview in the admin UI). */
        public ?string $sampleValue = null,
    ) {}
}
