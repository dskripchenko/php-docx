# dskripchenko/php-docx

Pure-PHP DOCX (Office Open XML) library: **bidirectional HTML ↔ DOCX**
conversion with no external dependencies beyond standard PHP extensions.

## Features

- **Writer** — HTML5 (with inline styles) → DOCX bytes.
- **Reader** — arbitrary DOCX (Word/Pages/LibreOffice) → AST → HTML.
- **AST** — typed value-objects (`Document`, `Section`, `Paragraph`, `Run`,
  `Table`, `ListNode`, `Image`, `Hyperlink`, `Bookmark`, `Field`, …)
  shared by both directions.
- **Variable detection** — MERGEFIELD / SDT content controls /
  configurable text patterns (`{{x}}`, `${x}`, `%x%`).

## Supported elements

| HTML side | OOXML side |
|---|---|
| `<p>`, `<h1..h6>`, `<br>`, `<hr>`, page-break | `<w:p>`, `<w:r>`, `<w:br>`, `<w:pStyle Heading1..6>` |
| `<strong>`, `<em>`, `<u>`, `<s>`, `<sup>`, `<sub>`, `<mark>` | `<w:b>`, `<w:i>`, `<w:u>`, `<w:strike>`, `<w:vertAlign>`, `<w:highlight>` |
| `<small>`, `<pre>`, `<code>`, `<kbd>`, `<samp>`, `<var>`, `<cite>`, `<dfn>`, `<q>` | font-family + size variants in `<w:rPr>` |
| `<table>` with `colspan`/`rowspan`/`width`/`<thead>`/`<tbody>`/`<caption>`/`<colgroup>` | `<w:tbl>` with `gridSpan`/`vMerge`/`tblGrid`/etc. |
| `<a href>` external + `<a href="#x">`/`<a id>` internal links | `<w:hyperlink>` r:id / w:anchor + `<w:bookmarkStart>`/`<w:bookmarkEnd>` |
| `<img src="data:...">` | `<w:drawing>` + `word/media/*` + rels |
| `<ul>`, `<ol type="aAiI" start="N">`, `<li value="N">`, nested 3+ levels | `<w:numbering>` with abstract/concrete defs |
| `<dl>`/`<dt>`/`<dd>`, `<figure>`/`<figcaption>` | paragraph pairs / caption-styled paragraph |
| Inline `style="..."` (font, color, bg, border, padding, alignment, …) | `<w:rPr>`/`<w:pPr>`/`<w:tcPr>` properties |
| `<page-number/>`, `<page-total/>`, `<current-date format="...">` | `<w:fldSimple w:instr="PAGE | NUMPAGES | DATE \\@ ...">` |
| Page setup: paper size, orientation, margins | `<w:pgSz>`, `<w:pgMar>` |
| Headers, footers, watermark text | `word/header*.xml`, `word/footer*.xml`, VML/DrawingML watermark |
| Custom heading/paragraph styles via `StyleRegistry` | `word/styles.xml` |

### Out of scope

Tracked changes, comments, embedded charts, OLE objects, footnotes/endnotes,
SmartArt, math equations (OMML), form fields, JavaScript / custom XML parts.

## HTML → DOCX

```php
use Dskripchenko\PhpDocx\Html\Converter;
use Dskripchenko\PhpDocx\Writer\Word2007Writer;

$html = <<<HTML
<h1>Invoice #42</h1>
<p>Total: <strong>500 USD</strong></p>
<table>
  <tr><td>Item A</td><td>250 USD</td></tr>
  <tr><td>Item B</td><td>250 USD</td></tr>
</table>
<p>Page <page-number/> of <page-total/></p>
HTML;

$document = (new Converter)->fromHtml($html);
file_put_contents('invoice.docx', (new Word2007Writer)->write($document));
```

## DOCX → HTML

```php
use Dskripchenko\PhpDocx\Reader\DocxReader;
use Dskripchenko\PhpDocx\Reader\DocxPackageReader;
use Dskripchenko\PhpDocx\Reader\VariableDetector;
use Dskripchenko\PhpDocx\Html\Serializer;

$bytes = file_get_contents('input.docx');

// AST из произвольного DOCX (Word/Pages/LibreOffice).
$document = (new DocxReader)->read($bytes);

// Опционально: вытащить переменные (MERGEFIELD / SDT / {{patterns}}).
$pkg = (new DocxPackageReader)->read($bytes);
$variables = (new VariableDetector)->detect($pkg);

// AST → HTML + извлечённые media bytes.
$imported = (new Serializer)->serialize($document, $variables);

echo $imported->bodyHtml;           // <h1>...</h1><p>...</p>...
echo $imported->headerHtml;         // ?string
echo $imported->footerHtml;         // ?string
echo $imported->watermarkText;      // ?string
$imported->pageSettings;            // PageSetup VO
$imported->variables;               // list<DetectedVariable>
$imported->media;                   // array<filename, bytes>
```

## Round-trip

```php
$ast = (new DocxReader)->read($originalDocxBytes);
$reEmitted = (new Word2007Writer)->write($ast);
// Valid DOCX, открывается в Word/Pages/LibreOffice без warning'ов.
```

## Requirements

- PHP **8.2+**
- `ext-zip`, `ext-dom`, `ext-mbstring`
- Zero composer-package dependencies.

## Development

```bash
composer install
composer test       # ~250 tests
composer stan       # phpstan level 8
```

## License

MIT
