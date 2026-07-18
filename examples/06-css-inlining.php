<?php

declare(strict_types=1);

// Stylesheets and classes: fromHtmlWithStyles() resolves <style> blocks
// and CSS classes into inline styles before parsing. Needs the optional
// suggested package: composer require tijsverkoyen/css-to-inline-styles

require __DIR__.'/bootstrap.php';

use Dskripchenko\PhpDocx\Html\Converter;
use Dskripchenko\PhpDocx\Writer\Word2007Writer;

if (! class_exists(\TijsVerkoyen\CssToInlineStyles\CssToInlineStyles::class)) {
    fwrite(STDERR, "skipped: composer require tijsverkoyen/css-to-inline-styles\n");
    exit(0);
}

$document = (new Converter)->fromHtmlWithStyles(<<<'HTML'
    <style>
        h1        { color: #2c3e50; }
        .brand    { color: #c0392b; font-weight: bold; }
        .fineprint{ font-size: 8pt; color: #888888; }
    </style>
    <h1>Styled with a stylesheet</h1>
    <p>Delivered by <span class="brand">Acme Corp</span>.</p>
    <p class="fineprint">Terms and conditions apply.</p>
    HTML);

save_sample('06-css-inlining.docx', (new Word2007Writer)->write($document));
