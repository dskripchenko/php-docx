<?php

declare(strict_types=1);

// Template variables: detect MERGEFIELDs, SDT content controls and
// {{x}} / ${x} / %x% text patterns in a DOCX, then substitute values
// while importing to HTML.

require __DIR__.'/bootstrap.php';

use Dskripchenko\PhpDocx\Html\Converter;
use Dskripchenko\PhpDocx\Html\Serializer;
use Dskripchenko\PhpDocx\Reader\DocxPackageReader;
use Dskripchenko\PhpDocx\Reader\DocxReader;
use Dskripchenko\PhpDocx\Reader\VariableDetector;
use Dskripchenko\PhpDocx\Writer\Word2007Writer;

// A template a customer might upload from Word:
$template = (new Word2007Writer)->write((new Converter)->fromHtml(
    '<h1>Agreement</h1><p>Between {{company}} and {{customer_name}}, total ${total}.</p>',
));
save_sample('04-template.docx', $template);

// 1. What variables does it contain?
$variables = (new VariableDetector)->detect((new DocxPackageReader)->read($template));
foreach ($variables as $v) {
    printf("found variable: %s (source: %s)\n", $v->name, $v->source->name);
}

// 2. Fill them in during HTML import:
$html = (new Serializer)->serialize((new DocxReader)->read($template), [
    'company' => 'Acme Corp',
    'customer_name' => 'Jane Roe',
    'total' => '4 500,00 €',
])->bodyHtml;

save_sample('04-variables-filled.html', $html);
