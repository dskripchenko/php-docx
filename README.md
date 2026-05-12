# dskripchenko/php-docx

Minimal HTML → DOCX (Office Open XML) converter without legacy dependencies.

Designed for **print-form use cases**: structured templates with paragraphs,
tables, images, headers/footers — not for arbitrary HTML content from the web.

## Status

🚧 **Phase 1 — skeleton.** API is being implemented incrementally; see ADR
in the parent project for the migration roadmap from `phpoffice/phpword`.

## Goals

- Pure-PHP, **zero external dependencies** beyond `ext-zip`, `ext-dom`, `ext-mbstring`.
- HTML5 input with inline `style` attributes (caller inlines CSS classes upstream).
- Predictable OOXML output — no quirks workarounds needed by consumers.
- PHP 8.2+ idioms — readonly classes, constructor promotion, enums where appropriate.
- Compatible with Microsoft Word 2007+, LibreOffice Writer, Apple Pages.

## Scope

| Supported | Out of scope |
|---|---|
| `<p>`, `<h1..h6>`, `<br>`, `<hr>` | Tracked changes, comments |
| `<table>` (incl. `<thead>/<tbody>`, `colspan`, `rowspan`, `width`) | Embedded charts, OLE objects |
| `<td>`/`<th>` styles: bg, borders, padding, vertical-align | Footnotes/endnotes (might add later) |
| `<strong>`, `<em>`, `<u>`, `<s>`, `<sub>`, `<sup>` | Custom XML parts |
| `<a href>` | Embedded SVG (caller rasterizes upstream) |
| `<img src="data:...">` + relationships | Math equations |
| `<ul>`, `<ol>`, `<li>` | Forms (`<input>`, `<form>`) |
| Inline `style="..."` (font, color, bg, border, padding, etc.) | JavaScript anything |
| Page setup: A4/A3/A5/Letter/Legal, P/L orientation, margins | |
| Headers/footers, watermark | |

## Quick start

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
HTML;

$document = (new Converter)->fromHtml($html);

$writer = new Word2007Writer;
file_put_contents('invoice.docx', $writer->write($document));
```

## Why?

The widely-used `phpoffice/phpword` 1.x has several rough edges when used
as an HTML → DOCX renderer:

- CSS shorthands like `background:#XXX` are ignored.
- Numeric `font-weight: 700` doesn't trigger bold.
- `width: 100%` on `<table>` inherits to every child `<td>`.
- `<br>` inside `<td>` rendered as `<w:br/>` which Pages.app ignores.
- `Cell::$noWrap = true` by default — multi-line cells collapse.
- HTML5 → strict XML loader incompatibility (void elements, entities).

This library is built specifically to avoid those problems for printable
template scenarios.

## Development

```bash
composer install
composer test
composer stan
```

## License

MIT
