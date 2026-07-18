<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Html;

/**
 * Preprocessing seam for HTML input. The converter itself understands
 * inline styles only; a preprocessor can transform full stylesheets
 * (`<style>` blocks, classes, external CSS) into inline styles before
 * conversion — or apply any other normalization.
 *
 * The bundled {@see CssInlinerPreprocessor} delegates to the optional
 * `tijsverkoyen/css-to-inline-styles` package (see composer `suggest`);
 * bring your own implementation for anything more elaborate.
 */
interface HtmlPreprocessor
{
    public function preprocess(string $html): string;
}
