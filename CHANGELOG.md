# Changelog

All notable changes to `dskripchenko/php-docx` are documented in this
file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versioning
follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] — 2026-05-13

Initial public release.

### HTML → DOCX
- `Html\Converter` — HTML5 subset to typed AST: paragraphs, headings
  (custom heading registry), tables, nested lists, images, hyperlinks,
  fields (`<page-number/>`, `<page-total/>`), inline-style resolution.
- `Writer\Word2007Writer` — OOXML (Word 2007+) emission: styles,
  numbering definitions, relationships, content types, media parts.

### DOCX → HTML / AST
- `Reader\DocxReader` — parses arbitrary Word / Pages / LibreOffice
  documents into a typed AST: style cascade (docDefaults → named →
  direct), theme colour resolution, numbering reconstruction,
  vMerge/gridSpan collapse, headers/footers, watermark detection
  (VML + DrawingML).
- `Html\Serializer` — AST back to HTML with inline styles
  (`ImportedDocument`: bodyHtml, headerHtml, footerHtml, watermarkText,
  pageSettings, variables, media).
- `Reader\VariableDetector` — MERGEFIELD, SDT content controls and
  configurable text patterns (`{{x}}`, `${x}`, `%x%`).

### Programmatic builder
- `Build\DocumentBuilder` with closure scopes: paragraphs with styled
  runs, tables (ColumnBuilder widths in pt/cm/inch/mm/px via `Length`
  helpers), nested bullet/ordered lists, images, fields, links,
  bookmarks, multi-header/footer (default / first-page / even-pages),
  text watermarks.

### Round-trip
- Read DOCX → AST → write DOCX produces a valid document; byte-level
  differences limited to whitespace/ordering.

Zero composer dependencies (`ext-zip`, `ext-dom`, `ext-mbstring`).
Docs in four languages (en/ru/zh/de). 345 tests.
