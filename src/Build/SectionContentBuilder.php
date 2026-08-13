<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Build;

/**
 * A minimal wrapper over AddsBlockContent — used for the header, footer and
 * cell contexts, which need a container with all the block adders but none of
 * the document-level specifics (pageSetup, watermark).
 *
 * For example: `$doc->header(fn(SectionContentBuilder $h) => $h->paragraph('Acme Inc.'))`.
 */
final class SectionContentBuilder
{
    use AddsBlockContent;
}
