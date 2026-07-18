<?php

declare(strict_types=1);

/**
 * Corpus generator: DOCX produced by PHPWord — the single most important
 * external producer for the companion strategy (php-docx is the HTML
 * layer next to PHPWord, so it must read PHPWord output flawlessly).
 *
 * Usage: php scripts/corpus/generate-phpword.php [out-dir]
 *   default out-dir: build/corpus
 */

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\IOFactory;

require __DIR__.'/vendor/autoload.php';

$outDir = $argv[1] ?? dirname(__DIR__, 2).'/build/corpus';
if (! is_dir($outDir) && ! mkdir($outDir, 0777, true)) {
    fwrite(STDERR, "Cannot create $outDir\n");
    exit(1);
}

// ---------------------------------------------------------------- basic
$word = new PhpWord;
$section = $word->addSection();
$section->addTitle('PHPWord basic document', 1);
$section->addText('Plain paragraph written by PHPWord.');
$run = $section->addTextRun();
$run->addText('Formatted run: ');
$run->addText('bold', ['bold' => true]);
$run->addText(', ');
$run->addText('italic', ['italic' => true]);
$run->addText(', ');
$run->addText('colored', ['color' => 'C0392B']);
$run->addText(' and кириллица.');
$section->addText('Justified paragraph with enough text to be meaningful for alignment checks in the reader.', null, ['alignment' => Jc::BOTH]);
IOFactory::createWriter($word, 'Word2007')->save($outDir.'/phpword-basic.docx');

// ---------------------------------------------------------------- table
$word = new PhpWord;
$section = $word->addSection();
$section->addTitle('PHPWord table features', 1);
$table = $section->addTable(['borderSize' => 4, 'borderColor' => '999999']);
$table->addRow();
$table->addCell(3000)->addText('Merged across', ['bold' => true]);
$cell = $table->addCell(3000);
$cell->getStyle()->setGridSpan(2);
$cell->addText('gridSpan 2');
$table->addRow();
$vm = $table->addCell(3000);
$vm->getStyle()->setVMerge('restart');
$vm->addText('vMerge start');
$table->addCell(3000)->addText('B2');
$table->addCell(3000)->addText('C2');
$table->addRow();
$cont = $table->addCell(3000);
$cont->getStyle()->setVMerge('continue');
$table->addCell(3000)->addText('B3');
$table->addCell(3000)->addText('C3');
IOFactory::createWriter($word, 'Word2007')->save($outDir.'/phpword-table.docx');

// ---------------------------------------------------------------- lists
$word = new PhpWord;
$section = $word->addSection();
$section->addTitle('PHPWord lists', 1);
$section->addListItem('Top level one', 0);
$section->addListItem('Nested a', 1);
$section->addListItem('Nested b', 1);
$section->addListItem('Deep i', 2);
$section->addListItem('Top level two', 0);
$section->addListItem('Numbered one', 0, null, \PhpOffice\PhpWord\Style\ListItem::TYPE_NUMBER);
$section->addListItem('Numbered two', 0, null, \PhpOffice\PhpWord\Style\ListItem::TYPE_NUMBER);
IOFactory::createWriter($word, 'Word2007')->save($outDir.'/phpword-lists.docx');

// ------------------------------------------------- headers/footers/link
$word = new PhpWord;
$section = $word->addSection();
$header = $section->addHeader();
$header->addText('PHPWord header text');
$footer = $section->addFooter();
$footer->addPreserveText('Page {PAGE} of {NUMPAGES}');
$section->addTitle('Headers, footers, links', 1);
$section->addLink('https://github.com/PHPOffice/PHPWord', 'PHPWord repository');
$section->addText('Body under header/footer.');
IOFactory::createWriter($word, 'Word2007')->save($outDir.'/phpword-headers.docx');

echo "generated 4 PHPWord corpus documents in $outDir\n";
