# Changelog

All notable changes to `dskripchenko/php-docx` are documented in this
file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versioning
follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] — 2026-07-18

### Fixed
- **Two ECMA-376 violations in the writer's output**, surfaced by the new
  schema-validation pass: paragraph properties emitted children out of
  the CT_PPrBase order (`jc` before `spacing`/`ind`, `pBdr` last), and
  `<w:pgMar>` omitted the required `w:gutter` attribute. Word tolerated
  both; strict consumers don't. Guarded by string-level order tests plus
  the real XSD validation in CI.

### Added
- **Conformance CI** (`conformance` workflow, badge in the README):
  every push generates a reference document and validates it externally —
  every WordprocessingML part against the official ECMA-376 Transitional
  XSD schemas (xmllint; schemas fetched from ecma-international.org,
  SHA-pinned), plus a consumer smoke: LibreOffice headless DOCX→PDF
  conversion and python-docx content extraction.
- PHP 8.5 added to the CI test matrix.

## [1.0.2] — 2026-07-18

### Fixed
- **Reader crashed on justified text.** `parseAlignment()` referenced the
  non-existent `Alignment::Both` enum case, so any document containing
  `<w:jc w:val="both"/>` — the default alignment of most formal
  documents, including this library's own output — threw an
  undefined-constant Error. `both` now maps to `Alignment::Justify` and
  `distribute` to `Alignment::Distribute`; every alignment case is
  covered by a write → read round-trip test.

### Changed
- PHPStan baseline reviewed finding-by-finding (46 → 42 raw errors): the
  alignment crash above was hiding in it as `classConstant.notFound`;
  the survivors are DOM-stub and by-ref-closure false positives plus
  defensive branches, documented as such. `readEntry()` gained a
  conditional return type so required entries no longer report as
  nullable.

## [1.0.1] — 2026-07-18

### Fixed
- **PHP 8.2 support restored** — typed class constants (`const string …`,
  a PHP 8.3+ feature) had crept into the codebase while composer.json
  declares `"php": "^8.2"`, so the package failed to parse on PHP 8.2.
  The constant types were removed; behaviour is unchanged. CI now runs
  the matrix on PHP 8.2–8.4 to prevent regressions.

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
