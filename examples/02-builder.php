<?php

declare(strict_types=1);

// Fluent builder: programmatic documents without touching HTML or XML.

require __DIR__.'/bootstrap.php';

use Dskripchenko\PhpDocx\Build\DocumentBuilder;

$bytes = DocumentBuilder::new()
    ->heading(1, 'Quarterly report')
    ->paragraph(fn ($p) => $p
        ->text('Prepared for ')
        ->bold('Acme Corp')
        ->text(' — Q2 2026, ')
        ->italic('confidential')
        ->text('.'))
    ->heading(2, 'Highlights')
    ->bulletList(fn ($l) => $l
        ->item('Revenue up 12% quarter over quarter')
        ->item('Two regions, one with details', fn ($nested) => $nested
            ->item('EMEA: +18%')
            ->item('APAC: +7%'))
        ->item('Churn down to 1.9%'))
    ->table(fn ($t) => $t
        ->headerRow(['Region', 'Revenue'])
        ->row(['EMEA', '$1.2M'])
        ->row(['APAC', '$0.8M']))
    ->pageBreak()
    ->heading(2, 'Appendix')
    ->paragraph('Full data set available on request.')
    ->footer(fn ($f) => $f->paragraph('Acme Corp — page footer'))
    ->watermark('DRAFT')
    ->toBytes();

save_sample('02-builder.docx', $bytes);
