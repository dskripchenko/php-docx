# Runnable examples

Each script is self-contained and writes its output into
[`samples/`](../samples) (committed as a gallery):

```bash
composer install
php examples/01-html-to-docx.php
```

| Example | Shows |
|---|---|
| [01-html-to-docx.php](01-html-to-docx.php) | HTML → DOCX with header, footer and watermark |
| [02-builder.php](02-builder.php) | fluent builder: headings, nested lists, tables, page breaks |
| [03-docx-to-html.php](03-docx-to-html.php) | DOCX → clean HTML (body + header + footer) |
| [04-variable-detection.php](04-variable-detection.php) | detect `{{x}}` / `${x}` / MERGEFIELD variables, substitute on import |
| [05-round-trip.php](05-round-trip.php) | HTML → DOCX → HTML fixed-point stability |
| [06-css-inlining.php](06-css-inlining.php) | `<style>` blocks / CSS classes via `fromHtmlWithStyles()`¹ |
| [07-phpword-bridge.php](07-phpword-bridge.php) | export a PhpWord object to HTML² |

¹ needs the suggested `tijsverkoyen/css-to-inline-styles` package.
² needs [`dskripchenko/php-docx-phpword`](https://github.com/dskripchenko/php-docx-phpword).
