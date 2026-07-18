<?php

declare(strict_types=1);

/**
 * Corpus generator: DOCX produced by php-docx itself — the round-trip
 * baseline (read-back of our own writer must be lossless on the modelled
 * feature set).
 *
 * Usage: php scripts/corpus/generate-own.php [out-dir]
 *   default: build/corpus
 */

use Dskripchenko\PhpDocx\Build\DocumentBuilder;
use Dskripchenko\PhpDocx\Element\ListFormat;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$outDir = $argv[1] ?? dirname(__DIR__, 2).'/build/corpus';
if (! is_dir($outDir) && ! mkdir($outDir, 0777, true)) {
    fwrite(STDERR, "Cannot create $outDir\n");
    exit(1);
}

DocumentBuilder::new()
    ->watermark('CORPUS')
    ->header(fn ($h) => $h->paragraph('php-docx corpus header'))
    ->footer(fn ($f) => $f->paragraph(fn ($p) => $p
        ->text('Page ')->pageNumber()->text(' of ')->totalPages()))
    ->heading(1, 'php-docx own-writer document')
    ->paragraph(fn ($p) => $p
        ->text('Runs: ')->bold('bold')->text(', ')->italic('italic')
        ->text(', кириллица, ')->text('link: ')
        ->link('https://github.com/dskripchenko/php-docx', 'repo'))
    ->paragraph(fn ($p) => $p->text('Justified with spacing and indent.')
        ->alignJustify()->spacing(before: 120, after: 120)->indent(left: 360))
    ->heading(2, 'Table')
    ->table(fn ($t) => $t
        ->headerRow(['H1', 'H2'])
        ->row(['A', 'B'])
        ->row(['C', 'D']))
    ->heading(2, 'Lists')
    ->orderedList(fn ($l) => $l
        ->format(ListFormat::Decimal)
        ->item('one')
        ->item('two with nested', fn ($n) => $n->item('nested a')->item('nested b'))
        ->item('three'))
    ->toFile($outDir.'/own-writer.docx');

echo "generated own-writer.docx in $outDir\n";
