<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Html;

use Dskripchenko\PhpDocx\Reader\DetectedVariable;
use Dskripchenko\PhpDocx\Style\PageSetup;

/**
 * Результат импорта DOCX (ADR-015 Phase 10).
 *
 * Передаётся в application-уровень (printable\Importer) который маппит
 * это в свою Template-структуру.
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
        public ?string $watermarkText = null,
        public PageSetup $pageSettings = new PageSetup,
        public array $variables = [],
        public array $media = [],
    ) {}
}
