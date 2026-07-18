<?php

declare(strict_types=1);

// PHPWord bridge: export an existing PhpWord object to clean HTML — the
// export PHPWord itself does not offer. Needs the companion package:
// composer require dskripchenko/php-docx-phpword

require __DIR__.'/bootstrap.php';

if (! class_exists(\Dskripchenko\PhpDocxPhpWord\PhpWordBridge::class)) {
    fwrite(STDERR, "skipped: composer require dskripchenko/php-docx-phpword\n");
    exit(0);
}

use Dskripchenko\PhpDocxPhpWord\PhpWordBridge;
use PhpOffice\PhpWord\PhpWord;

// The PHPWord code you already have:
$word = new PhpWord;
$section = $word->addSection();
$section->addTitle('From PHPWord', 1);
$run = $section->addTextRun();
$run->addText('Built with the PHPWord API, ');
$run->addText('exported by php-docx', ['bold' => true]);
$run->addText('.');

// One line to HTML:
save_sample('07-phpword-bridge.html', PhpWordBridge::toHtml($word));
