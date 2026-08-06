# dskripchenko/php-docx

> 🌐 **English** · [Deutsch](docs/de/README.md) · [Русский](docs/ru/README.md) · [中文](docs/zh/README.md)

[![Tests](https://img.shields.io/github/actions/workflow/status/dskripchenko/php-docx/tests.yml?branch=main&label=tests&logo=github)](https://github.com/dskripchenko/php-docx/actions/workflows/tests.yml)
[![Conformance](https://img.shields.io/github/actions/workflow/status/dskripchenko/php-docx/conformance.yml?branch=main&label=ECMA-376%20%C2%B7%20LibreOffice&logo=github)](https://github.com/dskripchenko/php-docx/actions/workflows/conformance.yml)
[![Latest Version](https://img.shields.io/packagist/v/dskripchenko/php-docx?logo=packagist&logoColor=white)](https://packagist.org/packages/dskripchenko/php-docx)
[![Total Downloads](https://img.shields.io/packagist/dt/dskripchenko/php-docx)](https://packagist.org/packages/dskripchenko/php-docx)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-purple.svg)](https://www.php.net)

**The HTML layer for your PHPWord pipeline — and a standalone
DOCX ↔ HTML round-trip library.** Pure-PHP: read arbitrary Word /
Google Docs / LibreOffice / PHPWord documents into a typed AST, turn
them into clean HTML, convert HTML into DOCX, detect template variables
(MERGEFIELD, content controls, `{{placeholders}}`). Works next to
[PHPWord](https://github.com/PHPOffice/PHPWord), not instead of it. No
external dependencies beyond standard PHP extensions.

**Read this in other languages:**
**English** ·
[Русский](docs/ru/README.md) ·
[中文](docs/zh/README.md) ·
[Deutsch](docs/de/README.md)

---

## Table of contents

- [Features](#features)
- [php-docx and PHPWord](#php-docx-and-phpword)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick start](#quick-start)
  - [HTML → DOCX](#1-html--docx)
  - [Programmatic builder](#2-programmatic-builder)
  - [DOCX → HTML / AST](#3-docx--html--ast)
- [HTML → DOCX](#html--docx-1)
- [Programmatic builder API](#programmatic-builder-api)
- [DOCX → HTML (Reader)](#docx--html-reader)
- [Headers, footers & watermarks](#headers-footers--watermarks)
- [Variable detection](#variable-detection)
- [Length helpers](#length-helpers)
- [AST overview](#ast-overview)
- [Round-trip](#round-trip)
- [Architecture](#architecture)
- [Development](#development)
- [License](#license)

---

## Features

- **HTML → DOCX writer** — full set of typical layout elements
  (paragraphs/headings/tables/lists/images/links/fields), inline-style
  resolution, custom heading registry.
- **DOCX → HTML reader** — parses arbitrary Word/Pages/LibreOffice
  documents into a typed AST, then serialises back to HTML with inline
  styles. Style cascade (docDefaults → named → direct), theme colors,
  numbering reconstruction, vMerge/gridSpan collapse, watermark
  detection (VML + DrawingML).
- **Fluent programmatic builder** — `DocumentBuilder` with closure
  scopes for nested structures (tables, lists, headers).
- **Variable detection** — MERGEFIELD, SDT content controls, configurable
  text patterns (`{{x}}`, `${x}`, `%x%`).
- **Multi-header/footer** — default / first-page / even-pages variants
  with automatic `<w:titlePg/>` and `<w:evenAndOddHeaders/>` plumbing.
- **Round-trip safe** — read DOCX → AST → write DOCX produces a valid
  document; bytes-level differences are limited to whitespace/ordering.
- **PHP 8.2+** — `readonly` value-objects, named arguments,
  constructor promotion, enums.
- **Zero composer dependencies.**

### Out of scope

Tracked changes, comments, embedded charts, OLE objects,
footnotes/endnotes, SmartArt, math equations (OMML), form fields,
custom XML parts.

---

## Examples

Runnable scripts with committed output live in
[`examples/`](examples/README.md): HTML → DOCX with headers and
watermarks, the fluent builder, DOCX → HTML import, template-variable
detection and substitution, round-trip stability, CSS inlining, and the
PHPWord bridge.

## php-docx and PHPWord

PHPWord is the established library for *building* Word documents in PHP.
php-docx does not replace it — it adds the two things PHPWord is weakest
at: **reading arbitrary DOCX** (with a full style cascade) and
**HTML in both directions**. Use both, each for what it does best.

What php-docx adds next to PHPWord:

- reading real-world DOCX (Word, Google Docs, LibreOffice, PHPWord
  output) into a typed AST — verified continuously on an external
  corpus, see the [reader-fidelity dashboard](docs/READER-FIDELITY.md);
- DOCX → clean HTML with inline styles (style cascade, theme colours,
  numbering, merged cells);
- HTML → DOCX;
- template-variable detection (MERGEFIELD, SDT content controls,
  `{{x}}` / `${x}` / `%x%` patterns).

What php-docx deliberately does **not** do (PHPWord or other tools do):
ODF/RTF/PDF output, tracked changes, comments, footnotes/endnotes,
charts, OMML math, form fields.

**Zero-integration recipe — works today, no bridge required.** PHPWord
writes the file, php-docx reads the bytes:

```php
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;
use Dskripchenko\PhpDocx\Reader\DocxReader;
use Dskripchenko\PhpDocx\Html\Serializer;

$phpWord = new PhpWord;
$section = $phpWord->addSection();
$section->addTitle('Quarterly report', 1);
$section->addText('Built with PHPWord, exported to HTML by php-docx.');

$tmp = tempnam(sys_get_temp_dir(), 'docx');
IOFactory::createWriter($phpWord, 'Word2007')->save($tmp);

$document = (new DocxReader)->read((string) file_get_contents($tmp));
$html = (new Serializer)->serialize($document)->bodyHtml;
```

A typed object-level bridge (`PhpWordBridge::toHtml($phpWord)`, HTML
import into PHPWord objects, reading straight into PHPWord models) ships
separately as `dskripchenko/php-docx-phpword`.

---

## Requirements

- PHP **8.2+**
- `ext-zip`, `ext-dom`, `ext-mbstring`

---

## Installation

```bash
composer require dskripchenko/php-docx
```

---

## Quick start

### 1. HTML → DOCX

```php
use Dskripchenko\PhpDocx\Html\Converter;
use Dskripchenko\PhpDocx\Writer\Word2007Writer;

$html = <<<HTML
<h1>Invoice #42</h1>
<p>Total: <strong>500 USD</strong></p>
<table>
  <tr><th>Item</th><th>Qty</th></tr>
  <tr><td>Widget</td><td>2</td></tr>
</table>
<p>Page <page-number/> of <page-total/></p>
HTML;

$doc = (new Converter)->fromHtml($html);
file_put_contents('invoice.docx', (new Word2007Writer)->write($doc));
```

### 2. Programmatic builder

```php
use Dskripchenko\PhpDocx\Build\DocumentBuilder;
use Dskripchenko\PhpDocx\Element\ListFormat;

DocumentBuilder::new()
    ->watermark('DRAFT')
    ->header(fn ($h) => $h->paragraph('Acme Inc.'))
    ->footer(fn ($f) => $f->paragraph(fn ($p) => $p
        ->text('Page ')->pageNumber()->text(' of ')->totalPages()
    ))
    ->heading(1, 'Invoice #42')
    ->paragraph(fn ($p) => $p
        ->text('Customer: ')->bold('Acme Co.')
        ->lineBreak()
        ->text('ID: ')->mergeField('CustomerID')
    )
    ->table(fn ($t) => $t
        ->columns(fn ($c) => $c->widthCm(8), fn ($c) => $c->widthCm(3))
        ->headerRow(['Item', 'Qty'])
        ->row(['Widget', '2'])
    )
    ->orderedList(fn ($l) => $l
        ->format(ListFormat::LowerLetter)
        ->item('Net 30 terms')
        ->item('Free shipping')
    )
    ->toFile('invoice.docx');
```

### 3. DOCX → HTML / AST

```php
use Dskripchenko\PhpDocx\Reader\DocxReader;
use Dskripchenko\PhpDocx\Reader\DocxPackageReader;
use Dskripchenko\PhpDocx\Reader\VariableDetector;
use Dskripchenko\PhpDocx\Html\Serializer;

$bytes = file_get_contents('input.docx');

$document = (new DocxReader)->read($bytes);

$pkg = (new DocxPackageReader)->read($bytes);
$variables = (new VariableDetector)->detect($pkg);

$imported = (new Serializer)->serialize($document, $variables);

echo $imported->bodyHtml;
echo $imported->headerHtml;
echo $imported->footerHtml;
echo $imported->watermarkText;
$imported->pageSettings;
$imported->variables;
$imported->media;
```

---

## HTML → DOCX

Input HTML must use **inline styles only** (no `<style>` blocks). Use a
CSS-inliner upstream if needed.

### Supported elements

| Category | HTML tags |
|---|---|
| Text blocks | `<p>`, `<h1..h6>`, `<div>`, `<pre>`, `<blockquote>` |
| Inline marks | `<strong>/<b>`, `<em>/<i>`, `<u>`, `<s>/<del>`, `<sup>`, `<sub>`, `<mark>` |
| Code/teletype | `<code>`, `<kbd>`, `<samp>`, `<var>`, `<cite>`, `<dfn>`, `<q>`, `<small>` |
| Links | `<a href>` external, `<a href="#anchor">` internal, `<a id>` bookmarks |
| Images | `<img src="data:image/...;base64,...">` |
| Tables | `<table>`, `<thead>/<tbody>`, `<tr>`, `<th>/<td>`, `<colgroup>/<col>`, `<caption>`, `colspan`, `rowspan` |
| Lists | `<ul>`, `<ol type="a/A/i/I" start="N">`, `<li value="N">`, `<dl>/<dt>/<dd>` |
| Custom tags | `<page-number/>`, `<page-total/>`, `<current-date format="...">`, `<page-break>` |
| Marker classes | `class="page-number"`, `class="page-total"`, `class="page-break"` — same fields, spelled so an HTML sanitizer keeps them |
| Layout | `<hr>`, `<br>`, `<figure>/<figcaption>` |

### Inline styles

The converter understands `style="…"` properties:

- Run-level: `font-family`, `font-size`, `font-weight`, `font-style`,
  `text-decoration`, `color`, `background-color`, `letter-spacing`
- Paragraph-level: `text-align`, `margin`, `text-indent`, `line-height`,
  `border`, `padding`, `background-color`
- Table-level: `width`, `border`, `border-collapse`
- Cell-level: `width`, `padding`, `border`, `vertical-align`,
  `background-color`

### Custom tags

```html
<p>Page <page-number/> of <page-total/></p>
<p>Generated on <current-date format="dd.MM.yyyy"/></p>
```

These become OOXML field codes (`<w:fldSimple w:instr="PAGE">`).

### Custom heading styles

```php
use Dskripchenko\PhpDocx\Style\StyleRegistry;
use Dskripchenko\PhpDocx\Style\RunStyle;
use Dskripchenko\PhpDocx\Style\ParagraphStyle;
use Dskripchenko\PhpDocx\Style\Alignment;

$styles = (new StyleRegistry)
    ->heading(1, new RunStyle(sizeHalfPoints: 44, bold: true), new ParagraphStyle(alignment: Alignment::Center))
    ->heading(2, new RunStyle(sizeHalfPoints: 28, bold: true));

$writer = new Word2007Writer($styles);
```

---

### Stylesheets and classes

`fromHtml()` understands **inline styles only**. For HTML carrying
`<style>` blocks or class-based styling, use `fromHtmlWithStyles()` —
it first runs the document through an `HtmlPreprocessor`. The default
implementation inlines CSS via the optional
[`tijsverkoyen/css-to-inline-styles`](https://github.com/tijsverkoyen/CssToInlineStyles)
package (`composer require tijsverkoyen/css-to-inline-styles`; php-docx
itself stays zero-dependency), and you can plug your own preprocessor:

```php
use Dskripchenko\PhpDocx\Html\Converter;

$doc = (new Converter)->fromHtmlWithStyles($htmlWithStyleBlocks);
// or: new Converter(preprocessor: new MyPreprocessor)
```

---

## Programmatic builder API

The `Build` namespace provides a fluent API for assembling DOCX
documents block by block, finalising to the same immutable AST that the
HTML pipeline produces.

### DocumentBuilder

Entry point. Accumulates body, header/footer, watermark, page setup.

```php
use Dskripchenko\PhpDocx\Build\DocumentBuilder;
use Dskripchenko\PhpDocx\Style\PageSetup;
use Dskripchenko\PhpDocx\Style\PaperSize;
use Dskripchenko\PhpDocx\Style\Orientation;

$doc = DocumentBuilder::new()
    ->pageSetup(new PageSetup(
        paperSize: PaperSize::A4,
        orientation: Orientation::Portrait,
    ))
    ->watermark('CONFIDENTIAL')
    ->heading(1, 'Report')
    ->paragraph('Body')
    ->build();           // → Document AST

$bytes = DocumentBuilder::new()->paragraph('Hi')->toBytes();
$count = DocumentBuilder::new()->paragraph('Hi')->toFile('out.docx');
```

### ParagraphBuilder

Inside `->paragraph(fn ($p) => …)`:

```php
->paragraph(fn ($p) => $p
    ->text('plain ')
    ->bold('bold ')
    ->italic('italic ')
    ->underline('under ')
    ->strike('strike ')
    ->sup('super')->text('script ')
    ->sub('sub')->text('script ')
    ->styled('red', fn ($s) => $s->color('ff0000')->bold())
    ->lineBreak()
    ->link('https://example.com', 'website')
    ->internalLink('section1', 'go to section 1')
    ->bookmark('anchor1', 'anchor target')
    ->pageNumber()
    ->totalPages()
    ->currentDate('yyyy-MM-dd')
    ->mergeField('CustomerName')
    ->image($img)
    ->imageFromFile('/path/to/logo.png', widthPx: 150, altText: 'Logo')
)
```

Paragraph-level styling:

```php
->paragraph(fn ($p) => $p
    ->alignCenter()           // or alignRight()/alignJustify()
    ->indentMm(left: 20, firstLine: 10)
    ->spacingPt(before: 6, after: 12)
    ->text('Indented & spaced')
)
```

### TableBuilder

```php
use Dskripchenko\PhpDocx\Build\{TableBuilder, TableRowBuilder, TableCellBuilder, ColumnBuilder};

->table(fn (TableBuilder $t) => $t
    ->caption('Sales 2026')
    ->column(fn (ColumnBuilder $c) => $c->widthCm(6))
    ->column(fn (ColumnBuilder $c) => $c->widthCm(3))
    ->widthPercent(100)
    ->alignCenter()
    ->cellMarginsMm(2)
    ->headerRow(['Item', 'Price'])
    ->row(['Apple', '10 USD'])
    ->row(fn (TableRowBuilder $r) => $r
        ->cell('Banana')
        ->cell(fn (TableCellBuilder $c) => $c
            ->backgroundColor('ffeb3b')
            ->valignCenter()
            ->paragraph(fn ($p) => $p->bold('20 USD'))
        )
    )
)
```

Spans and merges:

```php
->row(fn ($r) => $r
    ->cell(fn ($c) => $c->gridSpan(2)->paragraph('Wide header'))
)
->row(fn ($r) => $r
    ->cell(fn ($c) => $c->rowSpan(2)->paragraph('Tall'))
    ->cell('right')
)
```

### ListBuilder

```php
use Dskripchenko\PhpDocx\Build\ListBuilder;
use Dskripchenko\PhpDocx\Element\ListFormat;

->bulletList(fn (ListBuilder $l) => $l
    ->item('First')
    ->item('Second', fn ($n) => $n
        ->item('Nested A')
        ->item('Nested B')
    )
)

->orderedList(fn (ListBuilder $l) => $l
    ->format(ListFormat::LowerLetter)   // a, b, c
    ->startAt(3)
    ->item('item starts at "c"')
)
```

### RunStyleBuilder

Used inside `->styled(text, fn (RunStyleBuilder) => …)` or standalone via
`RunStyleBuilder::new()->…->build()`.

```php
RunStyleBuilder::new()
    ->bold()
    ->italic()
    ->underline()
    ->strike()
    ->color('ff0000')
    ->backgroundColor('eeeeee')
    ->highlight('yellow')
    ->fontFamily('Arial')
    ->fontSizePt(14.5)
    ->build();
```

### Length helpers

Convert common units to OOXML twips (1 twip = 1/20 pt). Used wherever a
twip int is expected.

```php
use Dskripchenko\PhpDocx\Build\Length;

Length::pt(12);     // 240
Length::mm(20);     // 1134
Length::cm(2.5);    // 1417
Length::inch(0.5);  // 720
Length::px(100);    // 1500  (CSS px @ 96 DPI)
```

Most builders expose unit-aware shortcuts:

- TableBuilder: `widthPt/widthMm/widthCm/widthInches`, `cellMarginsMm/cellMarginsPt`
- TableCellBuilder: `widthPt/Mm/Cm/Inches`, `paddingMm/Pt/Cm/Inches`
- ColumnBuilder: `widthPt/Mm/Cm/Inches/Px`
- ParagraphBuilder: `indentMm/Cm/Pt/Inches`, `spacingPt/Mm`
- RunStyleBuilder: `fontSizePt`

---

## DOCX → HTML (Reader)

### High-level: DocxReader

```php
use Dskripchenko\PhpDocx\Reader\DocxReader;

$document = (new DocxReader)->read(file_get_contents('input.docx'));
// → Document (AST)
```

This runs the full pipeline: package unpack → styles resolve →
body/header/footer parsing → vMerge/list reconstruction → image
extraction → watermark detection → page setup.

### Low-level: DocxPackageReader

If you need the raw OOXML parts:

```php
use Dskripchenko\PhpDocx\Reader\DocxPackageReader;

$pkg = (new DocxPackageReader)->read($bytes);

$pkg->documentXml;           // \DOMDocument
$pkg->stylesXml;             // ?\DOMDocument
$pkg->numberingXml;          // ?\DOMDocument
$pkg->themeXml;              // ?\DOMDocument
$pkg->settingsXml;           // ?\DOMDocument
$pkg->headers;               // array<path, \DOMDocument>
$pkg->footers;               // array<path, \DOMDocument>
$pkg->media;                 // array<path, bytes>
$pkg->documentRelationships();  // list<Relationship>
$pkg->resolveDocumentRel('rId7');  // Relationship
```

### Serializer: AST → HTML

```php
use Dskripchenko\PhpDocx\Html\Serializer;

$imported = (new Serializer)->serialize($document, $variables);

// ImportedDocument:
$imported->bodyHtml;         // string
$imported->headerHtml;       // ?string
$imported->footerHtml;       // ?string
$imported->watermarkText;    // ?string
$imported->pageSettings;     // PageSetup
$imported->variables;        // list<DetectedVariable>
$imported->media;            // array<filename, bytes>
```

HTML output uses inline styles only — re-loadable into the same library
via `Html\Converter::fromHtml($imported->bodyHtml)`.

---

## Headers, footers & watermarks

Three header/footer types are supported per section: `default`, `first`
(title page), `even` (even pages). Word automatically renders the right
one based on page number.

```php
DocumentBuilder::new()
    ->header(fn ($h) => $h->paragraph('Default header'))
    ->firstHeader(fn ($h) => $h->paragraph('Cover page'))
    ->evenHeader(fn ($h) => $h->paragraph(fn ($p) => $p
        ->text('Page ')->pageNumber()
    ))
    ->footer(fn ($f) => $f->paragraph('© 2026 Acme'))
    ->firstFooter(fn ($f) => $f->paragraph('Confidential'))
    ->evenFooter(fn ($f) => $f->paragraph('Even footer'))
    ->paragraph('Body')
    ->toFile('with-headers.docx');
```

The writer automatically:
- emits `<w:titlePg/>` in `sectPr` when first-page header/footer is set
- emits `word/settings.xml` with `<w:evenAndOddHeaders/>` when even
  header/footer is set

### Watermark

```php
DocumentBuilder::new()
    ->watermark('DRAFT')
    ->paragraph('Body')
    ->toFile('with-watermark.docx');
```

Renders as a 45°-rotated VML text shape on every page.

---

## Variable detection

Scans an imported DOCX for three kinds of variables:

1. **MERGEFIELD** — Word mail-merge native, both simple `<w:fldSimple>`
   and complex `<w:fldChar>` form.
2. **SDT content controls** — `<w:sdt>` with `<w:tag w:val="...">`.
3. **Text patterns** — configurable regexes (defaults: `{{name}}`,
   `${name}`, `%name%`).

```php
use Dskripchenko\PhpDocx\Reader\VariableDetector;

$pkg = (new DocxPackageReader)->read($bytes);
$detector = new VariableDetector;     // defaults
// Or with custom regexes:
$detector = new VariableDetector(['/\[\[(\w+)\]\]/']);

$variables = $detector->detect($pkg);
foreach ($variables as $v) {
    echo "{$v->name} ({$v->source->value})";
    echo " placeholder='{$v->placeholder}'";
    echo " sample='{$v->sampleValue}'\n";
}
```

Detection runs across `body + all headers + all footers`. Results are
deduplicated by `(source, name)`.

---

## Length helpers

See [Length helpers](#length-helpers) above. Conversion table:

| Unit | Twips | Pt | Notes |
|---|---|---|---|
| 1 twip | 1 | 0.05 | OOXML native |
| 1 pt | 20 | 1 | typography |
| 1 mm | ~57 | 2.83 | metric |
| 1 cm | ~567 | 28.35 | metric |
| 1 inch | 1440 | 72 | imperial |
| 1 px | 15 | 0.75 | CSS @ 96 DPI |

---

## AST overview

All elements live under `Dskripchenko\PhpDocx\Element` namespace.

| Element | Type | Notes |
|---|---|---|
| `Document` | root | `{ section: Section, watermarkText: ?string }` |
| `Section` | container | `{ body, header, footer, pageSetup, firstHeader, firstFooter, evenHeader, evenFooter }` |
| `Paragraph` | BlockElement | `{ children: InlineElement[], style: ParagraphStyle, headingLevel: ?int }` |
| `Run` | InlineElement | `{ text: string, style: RunStyle }` |
| `Hyperlink` | InlineElement | `{ href: ?string, anchor: ?string, children: InlineElement[] }` |
| `Bookmark` | InlineElement | `{ name: string, children: InlineElement[] }` |
| `Image` | both | `{ binary, format, widthEmu, heightEmu, altText }` |
| `Field` | InlineElement | `{ instruction: string, style: RunStyle }` |
| `LineBreak`, `PageBreak`, `HorizontalRule` | both | marker elements |
| `Table` | BlockElement | `{ rows: TableRow[], style, caption, gridColumnsTwips }` |
| `TableRow` | element | `{ cells: TableCell[], isHeader, heightTwips }` |
| `TableCell` | element | `{ children: BlockElement[], style: CellStyle }` |
| `ListNode` | BlockElement | `{ items: ListItem[], ordered, format, startAt }` |
| `ListItem` | element | `{ children: InlineElement[], nestedList: ?ListNode }` |

Styles live under `Dskripchenko\PhpDocx\Style`:

- `RunStyle` — font, weight, italic, color, size, highlight, …
- `ParagraphStyle` — alignment, indents, spacing, borders
- `CellStyle` — width, padding, borders, valign, gridSpan, rowSpan
- `TableStyle` — width, borders, alignment, cell margins, layout
- `PageSetup`, `PaperSize`, `Orientation`, `Alignment`, `VerticalAlign`,
  `BorderStyle`, `Border`, `BorderSet`

---

## Round-trip

```php
$bytes1 = file_get_contents('original.docx');
$doc = (new DocxReader)->read($bytes1);
$bytes2 = (new Word2007Writer)->write($doc);
file_put_contents('roundtrip.docx', $bytes2);
```

The library targets **semantic** round-trip safety, not byte equality —
content, structure and styling survive, but XML ordering and whitespace
may differ.

In-scope round-trip features:
- Paragraphs/headings with all run formatting
- Tables with `vMerge`/`gridSpan` reconstruction
- Lists (bullet/decimal/letter/roman) with arbitrary nesting
- Images with EMU sizes and alt text
- Hyperlinks (external + internal anchors) and bookmarks
- Headers/footers (default/first/even) and watermarks
- Field codes (PAGE, NUMPAGES, DATE, MERGEFIELD)
- Page setup (size, orientation, margins)

Out-of-scope features are silently dropped (footnotes, comments,
equations, etc.).

---

## Architecture

```
HTML (inline styles)
       │
       ▼  Html\Converter
   Document (AST)  ◀──── DocumentBuilder (programmatic)
       │
       ▼  Writer\Word2007Writer
   DOCX bytes
       ▲
       │  Reader\DocxReader
   Document (AST)
       │
       ▼  Html\Serializer
   ImportedDocument (bodyHtml, headerHtml, footerHtml, variables, media)
```

The same `Document` AST is shared by HTML conversion, programmatic
construction and DOCX reading — every entry/exit point operates on
typed value-objects.

---

## Conformance

Every push validates the writer's output externally, not just against
our own reader:

- **ECMA-376 Transitional XSD** — every WordprocessingML part
  (document, styles, numbering, headers, footers) is validated with
  xmllint against the official schemas (fetched from
  ecma-international.org, SHA-pinned);
- **LibreOffice headless** converts the reference document to PDF —
  a real-world consumer, not a mock;
- **python-docx** opens it and extracts the expected content — an
  independent reader implementation.

Reproduce locally:

```bash
bash scripts/fetch-ooxml-schemas.sh
php scripts/conformance/generate.php
bash scripts/conformance/xsd-check.sh
bash scripts/conformance/consumer-smoke.sh   # needs LibreOffice + python-docx
```

---

## Development

```bash
composer install
composer test       # phpunit suite (~340 tests)
composer stan       # phpstan level 8
```

---

## License

MIT — see [LICENSE](LICENSE).
