<?php

declare(strict_types=1);

/**
 * Generate the conformance reference DOCX — a document exercising every
 * writer feature at once: headings, justified paragraphs with spacing and
 * indents, tables with header rows, nested ordered lists, multi-part
 * headers/footers with page-number fields, bookmarks, hyperlinks, a text
 * watermark, and a second section (landscape, its own header, a custom page
 * size) whose first section ends in a table. Validated by:
 *
 *   scripts/conformance/xsd-check.sh       — ECMA-376 Transitional XSD
 *   scripts/conformance/consumer-smoke.sh  — LibreOffice + python-docx
 *
 * Usage: php scripts/conformance/generate.php [out-dir]
 *   (default: build/conformance)
 */

use Dskripchenko\PhpDocx\Build\DocumentBuilder;
use Dskripchenko\PhpDocx\Element\ListFormat;
use Dskripchenko\PhpDocx\Style\Alignment;
use Dskripchenko\PhpDocx\Style\Orientation;
use Dskripchenko\PhpDocx\Style\PageSetup;
use Dskripchenko\PhpDocx\Style\SectionStart;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$outDir = $argv[1] ?? dirname(__DIR__, 2).'/build/conformance';
if (! is_dir($outDir) && ! mkdir($outDir, 0777, true)) {
    fwrite(STDERR, "Cannot create $outDir\n");
    exit(1);
}

$written = DocumentBuilder::new()
    ->watermark('CONFORMANCE')
    ->header(fn ($h) => $h->paragraph('ACME Inc. — conformance reference'))
    ->footer(fn ($f) => $f->paragraph(fn ($p) => $p
        ->text('Page ')->pageNumber()->text(' of ')->totalPages()))
    ->heading(1, 'php-docx conformance reference')
    ->paragraph(fn ($p) => $p
        ->text('Justified paragraph with spacing, indents and ')
        ->bold('bold')
        ->text(' plus кириллица — the pPr child order and pgMar gutter '
            .'used to violate ECMA-376 here.')
        ->alignJustify()
        ->spacing(before: 120, after: 120)
        ->indent(left: 360, firstLine: 240))
    ->heading(2, 'Table')
    ->table(fn ($t) => $t
        ->headerRow(['Feature', 'Validated by'])
        ->row(['pPr order', 'wml.xsd'])
        ->row(['pgMar gutter', 'wml.xsd']))
    ->heading(2, 'Lists')
    ->orderedList(fn ($l) => $l
        ->format(ListFormat::LowerLetter)
        ->item('schema validation')
        ->item('LibreOffice conversion')
        ->item('python-docx extraction'))
    ->paragraph(fn ($p) => $p
        ->text('Link: ')
        ->link('https://github.com/dskripchenko/php-docx', 'repository'))
    ->table(fn ($t) => $t->row(['A section that ends in a table', 'w:sectPr in an added paragraph']))
    ->newSection(new PageSetup(orientation: Orientation::Landscape))
    ->header(fn ($h) => $h->paragraph('Landscape section header'))
    ->heading(2, 'Sections')
    ->paragraph('A landscape section with a header of its own.')
    ->newSection(new PageSetup(widthTwips: 8391, heightTwips: 11906), SectionStart::OddPage)
    ->paragraph('A custom page size, starting on an odd page; it inherits the header.')
    ->toFile($outDir.'/reference.docx');

printf("wrote %s/reference.docx (%d bytes)\n", $outDir, $written);
