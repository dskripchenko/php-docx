# Changelog

All notable changes to `dskripchenko/php-docx` are documented in this
file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versioning
follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.13.2] — 2026-08-05

### Fixed
- **A cell or a document ending with a table made Word offer to recover the
  file.** Both must end with a paragraph. The existing guard asked whether the
  cell held *any* paragraph, which a nested table satisfies from the inside
  while the cell itself still ends with `</w:tbl>`. A closing empty paragraph
  is now appended where the content does not already end with one — in table
  cells and at the end of the body.

## [1.13.1] — 2026-08-05

### Fixed
- **An image in a header or footer made Word declare the file damaged.** A
  relationship id inside `header1.xml` resolves against
  `word/_rels/header1.xml.rels`, but images used there were registered in the
  document's relationships instead. Word could not resolve them: the logo in
  the header printed as an empty box with a cross, and opening the file
  offered to recover it. Each header and footer part now carries its own
  relationships file, numbered from `rId1` within the part; the media itself
  stays shared, as `word/media` is a package folder rather than a part's.

## [1.13.0] — 2026-08-05

### Added
- **Footnotes are read** — `Element\Footnote`, serialized to HTML as
  `<span class="footnote">…</span>`. `word/footnotes.xml` and
  `w:footnoteReference` were passed over entirely, so the text at the foot of
  the page disappeared without a trace. The note's own marker is dropped: the
  number is drawn by whoever renders the page, and keeping it produced
  "1. 1Text".

## [1.12.0] — 2026-08-05

### Added
- **A list item carries its paragraph style** — `ListItem::$style`, serialized
  onto `<li>`. In Word a list item is an ordinary paragraph that happens to be
  numbered, and it carries everything a paragraph carries: alignment, indents,
  spacing, "keep with next". All of it was dropped on the way, so a section
  heading formatted as a numbered item lost its formatting and its pagination
  rules.

## [1.11.0] — 2026-08-05

### Added
- **`w:keepNext` is read and serialized** — `ParagraphStyle::$keepWithNext`,
  emitted to HTML as `break-after: avoid`. A section heading must not be left
  as the last line of a page; Word moves it to the next page together with
  what follows.

  Known gap: a list item (`<li>`) does not yet carry its paragraph style, so a
  heading formatted as a numbered list item loses the property on the way.

## [1.10.0] — 2026-08-05

### Fixed
- **Form-field placeholders no longer vanish.** The result of a complex field
  was discarded wholesale on the assumption that Word renders it itself. That
  is true for a page number or a date, which we generate ourselves, and for
  MERGEFIELD, which becomes a template variable — but for FORMTEXT the result
  *is* the visible content of the document. An unfilled form field shows the
  hint from `w:ffData/w:textInput/w:default`, and that is what the reader now
  returns. In an insurance application this is where the field captions
  "Наименование страхователя", "Юридический адрес" and "ИНН" disappeared to —
  5.6% of the reference document's text.

## [1.9.0] — 2026-08-05

### Added
- **`w:caps` and `w:smallCaps` are read and serialized** —
  `RunStyle::$allCaps` / `$smallCaps`, emitted to HTML as
  `text-transform: uppercase` and `font-variant: small-caps`. These are
  presentation rather than content: the text stays lower-case in the markup
  while Word draws it in capitals. Ignoring them left a policy heading in
  lower case against an all-caps original, and the line was much narrower than
  it should have been, so everything around it drifted.

## [1.8.2] — 2026-08-05

### Fixed
- **Complex-script properties no longer style Cyrillic.** `w:bCs`, `w:iCs` and
  `w:szCs` describe weight and size for Arabic, Hebrew and Indic scripts; Word
  draws Latin and Cyrillic runs carrying them as ordinary text. They were read
  on a par with `w:b`/`w:i`/`w:sz`, so a document with `<w:bCs/>` on every run
  — routine in forms — arrived entirely bold. A seven-page insurance
  application printed bold from top to bottom.

## [1.8.1] — 2026-08-05

### Fixed
- **The build broke on PHP 8.2**: 1.8.0 shipped typed constants — 8.3 syntax,
  while the package supports 8.2. The developer machine runs 8.5, so
  everything passed locally. Static analysis now runs against the minimum
  supported version (`phpVersion: 80200`), and a gap like this is caught
  before the tag.

## [1.8.0] — 2026-08-04

### Added
- **Line spacing reaches the HTML.** `w:spacing/@w:line` was read into
  `ParagraphStyle` but never made it into serialization at all: a document
  with one-and-a-half spacing printed tight. `exact`/`atLeast` are now carried
  as a line height in points, `auto` as a CSS multiplier.

  Single spacing (240) is deliberately not carried: in Word it is the font's
  natural line height (about 1.2 em), whereas a CSS multiplier is measured
  from the font size. Mapping 240 → 1.0 squeezed lines against the original —
  on a reference policy it immediately left the last page nearly empty. The
  print engine has its own notion of single spacing, and it is closer to
  typographic norm than any approximation of ours.
- `ParagraphStyle::$lineSpacingRule` — without the rule the spacing number
  means nothing.

## [1.7.0] — 2026-08-04

### Fixed
- **Paragraph-mark formatting no longer leaks into the text.** `w:pPr/w:rPr`
  describes the ¶ character — how text typed at the end of the paragraph will
  look; Word does not touch existing runs with it. These properties were mixed
  into the base run style, and any one of them spread across the whole
  paragraph. In a reference insurance policy the paragraph mark was flagged
  bold — and the entire document read as bold: lines came out 16% wider than
  the original and the layout drifted everywhere. Found by comparing the width
  of a single word against the reference PDF in printable.

## [1.6.0] — 2026-08-04

### Added
- **Floating object offset** — `Element\Image::$offsetYEmu` from
  `wp:positionV/wp:posOffset`, serialized to HTML as a signed `margin-top`.
  Word places stamps and signatures over finished text by giving them a
  negative offset from the anchor point; without it the object took a line of
  its own below the paragraph and pushed the document apart.

## [1.5.0] — 2026-08-04

### Added
- **First-page headers and footers in the HTML import** —
  `ImportedDocument::$firstHeaderHtml` / `$firstFooterHtml`. The reader read
  them into the AST while the serializer emitted only the default header and
  footer: the letterhead with the logo, which Word keeps as a separate part of
  the document, was lost on the way. In a reference policy this dropped the
  insurer's logo from the first page.

## [1.4.1] — 2026-08-04

### Fixed
- **The default document style took no part in the cascade.** Per ECMA-376,
  properties are assembled as docDefaults → the style with `w:default="1"` →
  the named style → direct formatting, and the resolver skipped the second
  layer entirely. Documents routinely declare one thing in docDefaults and
  another in the default style — and the latter must win. In a reference
  insurance policy docDefaults promised 8pt after every paragraph while the
  default style reset it to zero: each of the 246 paragraphs gained the extra
  8pt, and the document swelled from five pages to seven. Found by comparing
  print output against the original in printable.

## [1.4.0] — 2026-08-04

### Added
- **TIFF is read rather than silently discarded.** `detectFormat` knew
  png/jpeg/gif/bmp, while Word on macOS puts exactly TIFF into the container —
  such an image vanished from the AST without a trace, and the only way to
  notice was to compare the result against the original by eye. In a reference
  insurance policy this is how the logo and the signature facsimile went
  missing. The format is recognized both by extension and by signature
  (`II*\0` / `MM\0*`).
- `ImageFormat::isWebSafe()` — whether browsers and PDF engines draw the
  format. TIFF and BMP live happily inside DOCX and almost nowhere outside it:
  a consumer needs to know that up front, so it can convert the image rather
  than discover the gap in the finished document.

## [1.3.0] — 2026-08-02

### Added
- **Paragraph shading** — `ParagraphStyle::$shadingColor` → `<w:shd>` in
  `w:pPr`. Previously only a table cell could be filled, so a coloured band
  the width of a paragraph required a table for the sake of one line. CSS
  `background-color` / `background` on a block element now reaches DOCX; it is
  read back and serialized to HTML.
- **Letter spacing** — `RunStyle::$letterSpacingTwips` → `<w:spacing>` in
  `w:rPr` (negative tightens). Input is CSS `letter-spacing`; reading and
  round-trip serialization are in place.
- **Fields and breaks marked by class**: `<span class="page-number">`,
  `page-total` and `<div class="page-break">` are understood on a par with the
  dedicated `<page-number/>` and `<pagebreak/>` tags. HTML editors sanitize
  markup against a whitelist and lose an unknown tag, whereas a class on a
  `span` survives the cleanup — and, unlike the block form, the field stays
  within the line of text instead of breaking the paragraph.
- **A CSS image size outweighs the attribute**: `style="width:96pt"` on `<img>`
  sets the size in any unit. The `width` attribute carries no unit (per HTML
  it is always CSS pixels) and remains the fallback.

### Fixed
- `RunStyleApplier` lost the parent's `highlight`: any inline style on a
  nested tag stripped the highlight from `<mark>`.

## [1.2.1] — 2026-07-28

### Fixed
- Writer: a negative `indentFirstLineTwips` (hanging indent) was written as
  `w:firstLine="-N"` — invalid per ECMA-376 (ST_TwipsMeasure is unsigned);
  it is now `w:hanging="N"`. Found by the corpus harness on real Google Docs /
  Word documents.

### Added
- The manual corpus is filled with real documents: Google Docs, Word Online,
  Word desktop (Windows + Mac) — all four pass the full reader-fidelity cycle.
- Harness: text comparison ignores whitespace seams (they produced false
  "lost" reports at cell and run boundaries); ground truth excludes cached
  field values (`fldChar` separate..end and `fldSimple`) — a cached PAGE
  number is not text.

## [1.2.0] — 2026-07-18

### Added
- **`Converter::fromHtmlWithStyles()`** — resolves `<style>` blocks and
  CSS classes into the inline styles the converter understands before
  parsing, via the optional suggested `tijsverkoyen/css-to-inline-styles`
  package. New `HtmlPreprocessor` seam on the `Converter` constructor
  lets callers plug any preprocessing (the CSS inliner is the default).
- **Reader-fidelity harness on an external corpus** (`reader-fidelity`
  CI job, dashboard in `docs/READER-FIDELITY.md`): documents produced by
  PHPWord, python-docx, LibreOffice and php-docx itself are read to
  HTML and checked non-circularly — python-docx ground-truth text both
  directions, re-emitted DOCX validated against the ECMA-376 schemas,
  and a round-trip fixed-point stability check.
- Companion positioning in the README: php-docx as the HTML layer next
  to PHPWord, with a verified file-boundary recipe.

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
