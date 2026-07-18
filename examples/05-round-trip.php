<?php

declare(strict_types=1);

// Round trip: HTML → DOCX → HTML → DOCX. The second pass is a fixed
// point — reading its own output loses nothing. (The same property is
// enforced in CI for the whole external corpus.)

require __DIR__.'/bootstrap.php';

use Dskripchenko\PhpDocx\Html\Converter;
use Dskripchenko\PhpDocx\Html\Serializer;
use Dskripchenko\PhpDocx\Reader\DocxReader;
use Dskripchenko\PhpDocx\Writer\Word2007Writer;

$sourceHtml = '<h1>Round trip</h1>'
    .'<p style="text-align: justify">Styled <b>bold</b>, <i>italic</i> and '
    .'<span style="color: #c0392b">coloured</span> text, кириллица works too.</p>'
    .'<ul><li>alpha</li><li>beta</li></ul>';

$writer = new Word2007Writer;
$reader = new DocxReader;
$serializer = new Serializer;

$docx1 = $writer->write((new Converter)->fromHtml($sourceHtml));
$html1 = $serializer->serialize($reader->read($docx1))->bodyHtml;

$docx2 = $writer->write((new Converter)->fromHtml($html1));
$html2 = $serializer->serialize($reader->read($docx2))->bodyHtml;

printf("round-trip stable: %s\n", $html1 === $html2 ? 'yes' : 'NO');
save_sample('05-round-trip.docx', $docx2);
