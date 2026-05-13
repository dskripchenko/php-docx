# dskripchenko/php-docx

Pure-PHP библиотека для DOCX (Office Open XML): **двусторонняя конвертация
HTML ↔ DOCX**, **fluent программный builder**, **детекция переменных**,
**round-trip-safe AST**. Без внешних зависимостей, только стандартные
PHP-расширения.

**Read this in other languages:**
[English](../README.md) ·
**Русский** ·
[中文](zh.md) ·
[Deutsch](de.md)

---

## Содержание

- [Возможности](#возможности)
- [Требования](#требования)
- [Установка](#установка)
- [Быстрый старт](#быстрый-старт)
  - [HTML → DOCX](#1-html--docx)
  - [Программный builder](#2-программный-builder)
  - [DOCX → HTML / AST](#3-docx--html--ast)
- [HTML → DOCX](#html--docx-1)
- [Программный builder API](#программный-builder-api)
- [DOCX → HTML (Reader)](#docx--html-reader)
- [Headers, footers и watermark'и](#headers-footers-и-watermarki)
- [Детекция переменных](#детекция-переменных)
- [Length-хелперы](#length-хелперы)
- [AST overview](#ast-overview)
- [Round-trip](#round-trip)
- [Архитектура](#архитектура)
- [Разработка](#разработка)
- [Лицензия](#лицензия)

---

## Возможности

- **HTML → DOCX writer** — полный набор типичных layout-элементов
  (paragraphs/headings/tables/lists/images/links/fields), резолв
  inline-стилей, custom heading-registry.
- **DOCX → HTML reader** — парсит произвольные документы Word/Pages/
  LibreOffice в типизированное AST, затем сериализует обратно в HTML
  с inline-стилями. Cascade стилей (docDefaults → named → direct),
  theme-цвета, реконструкция numbering, vMerge/gridSpan collapse,
  детекция watermark (VML + DrawingML).
- **Fluent программный builder** — `DocumentBuilder` с closure-scope'ами
  для nested-структур (таблицы, списки, headers).
- **Детекция переменных** — MERGEFIELD, SDT content controls,
  настраиваемые текстовые паттерны (`{{x}}`, `${x}`, `%x%`).
- **Multi-header/footer** — default / first-page / even-pages варианты
  с автоматическим `<w:titlePg/>` и `<w:evenAndOddHeaders/>`.
- **Round-trip safe** — DOCX → AST → DOCX даёт валидный документ;
  байтовые различия ограничены пробелами/порядком атрибутов.
- **PHP 8.2+** — `readonly` value-objects, named аргументы,
  constructor promotion, enums.
- **Ноль composer-зависимостей.**

### Out of scope

Tracked changes, comments, embedded charts, OLE objects, footnotes/
endnotes, SmartArt, math equations (OMML), form fields, custom XML
parts.

---

## Требования

- PHP **8.2+**
- `ext-zip`, `ext-dom`, `ext-mbstring`

---

## Установка

```bash
composer require dskripchenko/php-docx
```

---

## Быстрый старт

### 1. HTML → DOCX

```php
use Dskripchenko\PhpDocx\Html\Converter;
use Dskripchenko\PhpDocx\Writer\Word2007Writer;

$html = <<<HTML
<h1>Счёт #42</h1>
<p>Итого: <strong>500 USD</strong></p>
<table>
  <tr><th>Товар</th><th>Кол-во</th></tr>
  <tr><td>Виджет</td><td>2</td></tr>
</table>
<p>Стр. <page-number/> из <page-total/></p>
HTML;

$doc = (new Converter)->fromHtml($html);
file_put_contents('invoice.docx', (new Word2007Writer)->write($doc));
```

### 2. Программный builder

```php
use Dskripchenko\PhpDocx\Build\DocumentBuilder;
use Dskripchenko\PhpDocx\Element\ListFormat;

DocumentBuilder::new()
    ->watermark('ОБРАЗЕЦ')
    ->header(fn ($h) => $h->paragraph('ООО «Акме»'))
    ->footer(fn ($f) => $f->paragraph(fn ($p) => $p
        ->text('Стр. ')->pageNumber()->text(' из ')->totalPages()
    ))
    ->heading(1, 'Счёт #42')
    ->paragraph(fn ($p) => $p
        ->text('Клиент: ')->bold('ООО «Акме»')
        ->lineBreak()
        ->text('ID: ')->mergeField('CustomerID')
    )
    ->table(fn ($t) => $t
        ->columns(fn ($c) => $c->widthCm(8), fn ($c) => $c->widthCm(3))
        ->headerRow(['Товар', 'Кол-во'])
        ->row(['Виджет', '2'])
    )
    ->orderedList(fn ($l) => $l
        ->format(ListFormat::LowerLetter)
        ->item('Условия: 30 дней')
        ->item('Доставка включена')
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

На вход — HTML с **только inline-стилями** (без `<style>`-блоков). Если
у вас CSS-классы — прогоните через CSS-inliner upstream.

### Поддерживаемые элементы

| Категория | HTML-теги |
|---|---|
| Текстовые блоки | `<p>`, `<h1..h6>`, `<div>`, `<pre>`, `<blockquote>` |
| Inline-разметка | `<strong>/<b>`, `<em>/<i>`, `<u>`, `<s>/<del>`, `<sup>`, `<sub>`, `<mark>` |
| Code/teletype | `<code>`, `<kbd>`, `<samp>`, `<var>`, `<cite>`, `<dfn>`, `<q>`, `<small>` |
| Ссылки | `<a href>` внешние, `<a href="#anchor">` внутренние, `<a id>` bookmarks |
| Картинки | `<img src="data:image/...;base64,...">` |
| Таблицы | `<table>`, `<thead>/<tbody>`, `<tr>`, `<th>/<td>`, `<colgroup>/<col>`, `<caption>`, `colspan`, `rowspan` |
| Списки | `<ul>`, `<ol type="a/A/i/I" start="N">`, `<li value="N">`, `<dl>/<dt>/<dd>` |
| Custom-теги | `<page-number/>`, `<page-total/>`, `<current-date format="...">`, `<page-break>` |
| Layout | `<hr>`, `<br>`, `<figure>/<figcaption>` |

### Inline-стили

Converter понимает `style="…"`:

- Run-уровень: `font-family`, `font-size`, `font-weight`, `font-style`,
  `text-decoration`, `color`, `background-color`
- Paragraph-уровень: `text-align`, `margin`, `text-indent`,
  `line-height`, `border`, `padding`
- Table-уровень: `width`, `border`, `border-collapse`
- Cell-уровень: `width`, `padding`, `border`, `vertical-align`,
  `background-color`

### Custom-теги

```html
<p>Стр. <page-number/> из <page-total/></p>
<p>Сгенерировано <current-date format="dd.MM.yyyy"/></p>
```

Превращаются в OOXML field codes (`<w:fldSimple w:instr="PAGE">`).

### Кастомные heading-стили

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

## Программный builder API

Namespace `Build` предоставляет fluent API для поэтапной сборки DOCX
поблочно. На выходе — то же immutable AST, что и у HTML-pipeline.

### DocumentBuilder

Точка входа. Накапливает body, header/footer, watermark, page setup.

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
    ->watermark('КОНФИДЕНЦИАЛЬНО')
    ->heading(1, 'Отчёт')
    ->paragraph('Тело документа')
    ->build();           // → Document AST

$bytes = DocumentBuilder::new()->paragraph('Привет')->toBytes();
$count = DocumentBuilder::new()->paragraph('Привет')->toFile('out.docx');
```

### ParagraphBuilder

Внутри `->paragraph(fn ($p) => …)`:

```php
->paragraph(fn ($p) => $p
    ->text('обычный ')
    ->bold('жирный ')
    ->italic('курсив ')
    ->underline('подчёрк ')
    ->strike('зачёрк ')
    ->sup('верх')->text(' и ')
    ->sub('низ')->text(' индексы ')
    ->styled('красный', fn ($s) => $s->color('ff0000')->bold())
    ->lineBreak()
    ->link('https://example.com', 'сайт')
    ->internalLink('section1', 'к разделу 1')
    ->bookmark('anchor1', 'якорь')
    ->pageNumber()
    ->totalPages()
    ->currentDate('yyyy-MM-dd')
    ->mergeField('CustomerName')
    ->image($img)
    ->imageFromFile('/path/to/logo.png', widthPx: 150, altText: 'Логотип')
)
```

Стилизация параграфа:

```php
->paragraph(fn ($p) => $p
    ->alignCenter()           // alignRight()/alignJustify()
    ->indentMm(left: 20, firstLine: 10)
    ->spacingPt(before: 6, after: 12)
    ->text('С отступами')
)
```

### TableBuilder

```php
use Dskripchenko\PhpDocx\Build\{TableBuilder, TableRowBuilder, TableCellBuilder, ColumnBuilder};

->table(fn (TableBuilder $t) => $t
    ->caption('Продажи 2026')
    ->column(fn (ColumnBuilder $c) => $c->widthCm(6))
    ->column(fn (ColumnBuilder $c) => $c->widthCm(3))
    ->widthPercent(100)
    ->alignCenter()
    ->cellMarginsMm(2)
    ->headerRow(['Товар', 'Цена'])
    ->row(['Яблоко', '10 USD'])
    ->row(fn (TableRowBuilder $r) => $r
        ->cell('Банан')
        ->cell(fn (TableCellBuilder $c) => $c
            ->backgroundColor('ffeb3b')
            ->valignCenter()
            ->paragraph(fn ($p) => $p->bold('20 USD'))
        )
    )
)
```

Объединение ячеек:

```php
->row(fn ($r) => $r
    ->cell(fn ($c) => $c->gridSpan(2)->paragraph('Широкий header'))
)
->row(fn ($r) => $r
    ->cell(fn ($c) => $c->rowSpan(2)->paragraph('Высокая'))
    ->cell('справа')
)
```

### ListBuilder

```php
use Dskripchenko\PhpDocx\Build\ListBuilder;
use Dskripchenko\PhpDocx\Element\ListFormat;

->bulletList(fn (ListBuilder $l) => $l
    ->item('Первый')
    ->item('Второй', fn ($n) => $n
        ->item('Вложенный A')
        ->item('Вложенный B')
    )
)

->orderedList(fn (ListBuilder $l) => $l
    ->format(ListFormat::LowerLetter)   // a, b, c
    ->startAt(3)
    ->item('начинается с "c"')
)
```

### RunStyleBuilder

Используется внутри `->styled(text, fn (RunStyleBuilder) => …)` или
standalone через `RunStyleBuilder::new()->…->build()`.

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

### Length-хелперы

Конвертация привычных единиц в OOXML-нативные twips (1 twip = 1/20 pt).

```php
use Dskripchenko\PhpDocx\Build\Length;

Length::pt(12);     // 240
Length::mm(20);     // 1134
Length::cm(2.5);    // 1417
Length::inch(0.5);  // 720
Length::px(100);    // 1500  (CSS px @ 96 DPI)
```

Большинство builder'ов имеют unit-aware shortcut'ы:

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

Запускает полный pipeline: распаковка → резолв стилей → парсинг
body/header/footer → реконструкция vMerge/списков → извлечение картинок
→ детекция watermark → page setup.

### Low-level: DocxPackageReader

Если нужны raw OOXML-части:

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
$pkg->documentRelationships();
$pkg->resolveDocumentRel('rId7');
```

### Serializer: AST → HTML

```php
use Dskripchenko\PhpDocx\Html\Serializer;

$imported = (new Serializer)->serialize($document, $variables);

// ImportedDocument:
$imported->bodyHtml;
$imported->headerHtml;
$imported->footerHtml;
$imported->watermarkText;
$imported->pageSettings;
$imported->variables;
$imported->media;
```

HTML-output использует только inline-стили — можно подгрузить обратно
через `Html\Converter::fromHtml($imported->bodyHtml)`.

---

## Headers, footers и watermark'и

В секции поддерживаются 3 типа header/footer'ов: `default`, `first`
(титульная страница), `even` (чётные страницы). Word автоматически
рендерит нужный по номеру страницы.

```php
DocumentBuilder::new()
    ->header(fn ($h) => $h->paragraph('Default header'))
    ->firstHeader(fn ($h) => $h->paragraph('Cover page'))
    ->evenHeader(fn ($h) => $h->paragraph(fn ($p) => $p
        ->text('Стр. ')->pageNumber()
    ))
    ->footer(fn ($f) => $f->paragraph('© 2026 Акме'))
    ->firstFooter(fn ($f) => $f->paragraph('Конфиденциально'))
    ->evenFooter(fn ($f) => $f->paragraph('Чётный footer'))
    ->paragraph('Тело')
    ->toFile('with-headers.docx');
```

Writer автоматически:
- эмитит `<w:titlePg/>` в `sectPr` если задан first-header/footer
- эмитит `word/settings.xml` с `<w:evenAndOddHeaders/>` если задан
  even-header/footer

### Watermark

```php
DocumentBuilder::new()
    ->watermark('ОБРАЗЕЦ')
    ->paragraph('Body')
    ->toFile('with-watermark.docx');
```

Рендерится как VML text-shape с rotation 45° на каждой странице.

---

## Детекция переменных

Сканирует DOCX и находит переменные трёх типов:

1. **MERGEFIELD** — Word mail-merge, и `<w:fldSimple>`, и complex
   `<w:fldChar>` форма.
2. **SDT content controls** — `<w:sdt>` с `<w:tag w:val="...">`.
3. **Текстовые паттерны** — настраиваемые regex'ы (по умолчанию:
   `{{name}}`, `${name}`, `%name%`).

```php
use Dskripchenko\PhpDocx\Reader\VariableDetector;

$pkg = (new DocxPackageReader)->read($bytes);
$detector = new VariableDetector;         // defaults
// Или с кастомными regex:
$detector = new VariableDetector(['/\[\[(\w+)\]\]/']);

$variables = $detector->detect($pkg);
foreach ($variables as $v) {
    echo "{$v->name} ({$v->source->value})";
    echo " placeholder='{$v->placeholder}'";
    echo " sample='{$v->sampleValue}'\n";
}
```

Детекция работает по `body + all headers + all footers`. Дубликаты
схлопываются по `(source, name)`.

---

## Length-хелперы

Таблица конверсий:

| Единица | Twips | Pt | Заметки |
|---|---|---|---|
| 1 twip | 1 | 0.05 | OOXML native |
| 1 pt | 20 | 1 | типографика |
| 1 mm | ~57 | 2.83 | метрика |
| 1 cm | ~567 | 28.35 | метрика |
| 1 inch | 1440 | 72 | imperial |
| 1 px | 15 | 0.75 | CSS @ 96 DPI |

---

## AST overview

Все элементы в namespace `Dskripchenko\PhpDocx\Element`.

| Элемент | Тип | Заметки |
|---|---|---|
| `Document` | root | `{ section: Section, watermarkText: ?string }` |
| `Section` | container | `{ body, header, footer, pageSetup, firstHeader, firstFooter, evenHeader, evenFooter }` |
| `Paragraph` | BlockElement | `{ children: InlineElement[], style, headingLevel: ?int }` |
| `Run` | InlineElement | `{ text: string, style: RunStyle }` |
| `Hyperlink` | InlineElement | `{ href: ?string, anchor: ?string, children }` |
| `Bookmark` | InlineElement | `{ name: string, children }` |
| `Image` | both | `{ binary, format, widthEmu, heightEmu, altText }` |
| `Field` | InlineElement | `{ instruction: string, style: RunStyle }` |
| `LineBreak`, `PageBreak`, `HorizontalRule` | both | marker-элементы |
| `Table` | BlockElement | `{ rows: TableRow[], style, caption, gridColumnsTwips }` |
| `TableRow` | element | `{ cells: TableCell[], isHeader, heightTwips }` |
| `TableCell` | element | `{ children: BlockElement[], style: CellStyle }` |
| `ListNode` | BlockElement | `{ items: ListItem[], ordered, format, startAt }` |
| `ListItem` | element | `{ children: InlineElement[], nestedList: ?ListNode }` |

Стили — namespace `Dskripchenko\PhpDocx\Style`:

- `RunStyle` — шрифт, weight, italic, color, size, highlight, …
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

Библиотека гарантирует **семантическую** round-trip safety, не байтовое
равенство — content, structure, styling сохраняются, но XML ordering и
whitespace могут отличаться.

In-scope:
- Paragraphs/headings со всем run-форматированием
- Таблицы с `vMerge`/`gridSpan` реконструкцией
- Списки (bullet/decimal/letter/roman) с произвольным nesting'ом
- Image'и с EMU-размерами и alt
- Hyperlinks (внешние + internal anchors) и bookmarks
- Headers/footers (default/first/even) и watermark'и
- Field codes (PAGE, NUMPAGES, DATE, MERGEFIELD)
- Page setup (size, orientation, margins)

Out-of-scope элементы тихо дропаются (footnotes, comments, equations и т.п.).

---

## Архитектура

```
HTML (inline styles)
       │
       ▼  Html\Converter
   Document (AST)  ◀──── DocumentBuilder (программный)
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

Тот же `Document`-AST шарится HTML-конверсией, программным builder'ом
и DOCX-чтением — каждая точка входа/выхода оперирует typed value-objects.

---

## Разработка

```bash
composer install
composer test       # phpunit suite (~340 тестов)
composer stan       # phpstan level 8
```

---

## Лицензия

MIT — см. [LICENSE](../LICENSE).
