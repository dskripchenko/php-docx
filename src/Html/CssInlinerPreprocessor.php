<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Html;

use TijsVerkoyen\CssToInlineStyles\CssToInlineStyles;

/**
 * Default {@see HtmlPreprocessor}: inlines `<style>` blocks and extra CSS
 * into style="" attributes using the optional
 * `tijsverkoyen/css-to-inline-styles` package.
 *
 * The dependency is deliberately NOT required by php-docx (zero-deps
 * stays true); install it explicitly:
 *
 *     composer require tijsverkoyen/css-to-inline-styles
 */
final class CssInlinerPreprocessor implements HtmlPreprocessor
{
    private readonly CssToInlineStyles $inliner;

    /**
     * @param  string  $extraCss  Additional stylesheet applied on top of
     *                            any `<style>` blocks found in the HTML.
     */
    public function __construct(private readonly string $extraCss = '')
    {
        if (! class_exists(CssToInlineStyles::class)) {
            throw new \RuntimeException(
                'CssInlinerPreprocessor needs the optional css inliner package. '
                .'Install it with: composer require tijsverkoyen/css-to-inline-styles '
                .'— or provide your own '.HtmlPreprocessor::class.' implementation.',
            );
        }
        $this->inliner = new CssToInlineStyles;
    }

    public function preprocess(string $html): string
    {
        return $this->inliner->convert($html, $this->extraCss);
    }
}
