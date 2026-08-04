# Changelog

All notable changes to `dskripchenko/php-docx` are documented in this
file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versioning
follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.6.0] — 2026-08-04

### Added
- **Смещение плавающего объекта** — `Element\Image::$offsetYEmu` из
  `wp:positionV/wp:posOffset`, в HTML-сериализации — `margin-top` со знаком.
  Word ставит печати и подписи поверх готового текста, задавая отрицательное
  смещение относительно точки привязки; без него объект вставал отдельной
  строкой под абзацем и раздвигал документ.

## [1.5.0] — 2026-08-04

### Added
- **Колонтитулы первой страницы в HTML-импорте** —
  `ImportedDocument::$firstHeaderHtml` / `$firstFooterHtml`. Ридер их читал и
  клал в AST, а сериализатор отдавал только колонтитул по умолчанию: шапка с
  логотипом, которую Word держит отдельной частью документа, пропадала по
  дороге. В эталонном полисе так терялся логотип страховщика на первой
  странице.

## [1.4.1] — 2026-08-04

### Fixed
- **Стиль документа по умолчанию не участвовал в каскаде.** По ECMA-376
  свойства собираются как docDefaults → стиль с `w:default="1"` → именованный
  стиль → прямое форматирование, а резолвер пропускал второй слой целиком.
  Документы сплошь и рядом задают в docDefaults одно, а в стиле по умолчанию
  другое — и побеждать должно второе. В эталонном страховом полисе docDefaults
  обещал 8pt после каждого абзаца, а стиль по умолчанию сбрасывал их в ноль:
  лишние 8pt получал каждый из 246 абзацев, и документ распухал с пяти страниц
  до семи. Найдено сравнением печати с оригиналом в printable.

## [1.4.0] — 2026-08-04

### Added
- **TIFF читается, а не выбрасывается молча.** `detectFormat` знал png/jpeg/
  gif/bmp, а Word на macOS кладёт в контейнер именно TIFF — такая картинка
  исчезала из AST без единого следа, и заметить это можно было только сравнив
  результат с оригиналом глазами. В эталонном страховом полисе так пропадали
  логотип и факсимиле подписи. Формат распознаётся и по расширению, и по
  сигнатуре (`II*\0` / `MM\0*`).
- `ImageFormat::isWebSafe()` — рисуют ли формат браузеры и PDF-движки. TIFF и
  BMP в DOCX живут, но за его пределами почти нигде: потребителю нужно знать
  это заранее, чтобы сконвертировать картинку, а не выяснять по пустому месту
  в документе.

## [1.3.0] — 2026-08-02

### Added
- **Заливка абзаца** — `ParagraphStyle::$shadingColor` → `<w:shd>` в
  `w:pPr`. Раньше залить можно было только ячейку таблицы, из-за чего
  цветная плашка на ширину абзаца требовала таблицы ради одной строки.
  CSS `background-color` / `background` на блочном элементе теперь
  доезжает до DOCX; читается обратно и сериализуется в HTML.
- **Разрядка символов** — `RunStyle::$letterSpacingTwips` → `<w:spacing>`
  в `w:rPr` (отрицательная сжимает). Ввод — CSS `letter-spacing`, чтение
  и обратная сериализация на месте.
- **Поля и разрывы, размеченные классом**: `<span class="page-number">`,
  `page-total`, `<div class="page-break">` понимаются наравне с
  собственными тегами `<page-number/>` и `<pagebreak/>`. HTML-редакторы
  чистят разметку по whitelist'у и незнакомый тег теряют, а класс на
  `span` переживает чистку — и, в отличие от блочного варианта, поле
  остаётся в строке текста, а не разрывает абзац.

- **CSS-размер картинки важнее атрибута**: `style="width:96pt"` на `<img>`
  задаёт размер в любой единице. Атрибут `width` единицу не несёт (по
  HTML это всегда css-пиксели) и остаётся запасным вариантом.

### Fixed
- `RunStyleApplier` терял `highlight` родителя: любой inline-стиль на
  вложенном теге снимал подсветку с `<mark>`.

## [1.2.1] - 2026-07-28

### Fixed
- Writer: отрицательный `indentFirstLineTwips` (hanging-отступ) писался
  `w:firstLine="-N"` — невалидно по ECMA-376 (ST_TwipsMeasure unsigned);
  теперь `w:hanging="N"`. Найдено corpus-харнессом на реальных
  документах Google Docs / Word.

### Added
- Ручной corpus заполнен реальными документами: Google Docs,
  Word Online, Word desktop (Windows + Mac) — все 4 проходят полный
  reader-fidelity цикл.
- Харнесс: сравнение текста без пробельных швов (ложные lost на
  границах ячеек/рунов); ground truth исключает кэшированные значения
  полей (fldChar separate..end и fldSimple) — PAGE-кэш не текст.

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
