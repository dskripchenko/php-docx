<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Html;

use Dskripchenko\PhpDocx\Reader\DetectedVariable;
use Dskripchenko\PhpDocx\Style\PageSetup;

/**
 * The result of a DOCX import (ADR-015 Phase 10).
 *
 * It is handed to the application level (printable\Importer), which maps it
 * onto its own Template structure.
 */
final readonly class ImportedDocument
{
    /**
     * @param  list<DetectedVariable>  $variables
     * @param  array<string, string>  $media  placeholder filename (img1.png) →
     *                                        raw binary bytes
     */
    public function __construct(
        public string $bodyHtml,
        public ?string $headerHtml = null,
        public ?string $footerHtml = null,
        /**
         * The first-page header and footer, when the document defines them
         * separately.
         *
         * Word keeps them as separate parts, and what looks different on the
         * first page lives there: the letterhead with the logo, the title
         * block. The reader read them, but the serializer emitted only the
         * default header — and the logo went missing on the way, even though
         * it was in the AST.
         */
        public ?string $firstHeaderHtml = null,
        public ?string $firstFooterHtml = null,
        public ?string $watermarkText = null,
        public PageSetup $pageSettings = new PageSetup,
        public array $variables = [],
        public array $media = [],
    ) {}
}
