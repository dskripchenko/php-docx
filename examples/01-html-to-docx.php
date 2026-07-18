<?php

declare(strict_types=1);

// HTML → DOCX: the one-liner path. Inline styles, headings, tables,
// lists, header/footer HTML and a text watermark.

require __DIR__.'/bootstrap.php';

use Dskripchenko\PhpDocx\Html\Converter;
use Dskripchenko\PhpDocx\Writer\Word2007Writer;

$document = (new Converter)->fromHtml(
    body: <<<'HTML'
        <h1>Invoice #1234</h1>
        <p>Customer: <b>Acme Corp</b> — due <u>2026-08-01</u></p>
        <table>
          <tr><th>Item</th><th>Qty</th><th>Price</th></tr>
          <tr><td>Widget</td><td>2</td><td>$10.00</td></tr>
          <tr><td>Gadget</td><td>1</td><td>$25.00</td></tr>
        </table>
        <p style="text-align: right"><b>Total: $45.00</b></p>
        <ul><li>Payment within 14 days</li><li>Late fee 1.5%/month</li></ul>
        HTML,
    header: '<p style="text-align: right; color: #888888">Acme Corp — internal</p>',
    footer: '<p style="text-align: center">Generated with php-docx</p>',
    watermarkText: 'PAID',
);

save_sample('01-html-to-docx.docx', (new Word2007Writer)->write($document));
