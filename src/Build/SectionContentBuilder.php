<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Build;

/**
 * Минимальный wrapper над AddsBlockContent — используется для header/
 * footer/cell контекстов где нужно «container со всеми block-adder'ами»
 * без специфичной document-level логики (pageSetup/watermark).
 *
 * Например: `$doc->header(fn(SectionContentBuilder $h) => $h->paragraph('Acme Inc.'))`.
 */
final class SectionContentBuilder
{
    use AddsBlockContent;
}
