<?php

declare(strict_types=1);

// DOCX → HTML: read a document (here: the one example 01 produced —
// works the same for files from Word, Google Docs, LibreOffice or
// PHPWord) and serialize it to clean HTML with inline styles.

require __DIR__.'/bootstrap.php';

use Dskripchenko\PhpDocx\Html\Serializer;
use Dskripchenko\PhpDocx\Reader\DocxReader;

$source = SAMPLES_DIR.'/01-html-to-docx.docx';
if (! is_readable($source)) {
    fwrite(STDERR, "run examples/01-html-to-docx.php first\n");
    exit(1);
}

$document = (new DocxReader)->read((string) file_get_contents($source));
$imported = (new Serializer)->serialize($document);

$page = <<<HTML
    <!-- header --> {$imported->headerHtml}
    <!-- body   --> {$imported->bodyHtml}
    <!-- footer --> {$imported->footerHtml}
    <!-- watermark: {$imported->watermarkText} -->
    HTML;

save_sample('03-docx-to-html.html', $page);
