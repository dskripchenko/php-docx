# dskripchenko/php-docx

[![Tests](https://img.shields.io/github/actions/workflow/status/dskripchenko/php-docx/tests.yml?branch=main&label=tests&logo=github)](https://github.com/dskripchenko/php-docx/actions/workflows/tests.yml)
[![Conformance](https://img.shields.io/github/actions/workflow/status/dskripchenko/php-docx/conformance.yml?branch=main&label=ECMA-376%20%C2%B7%20LibreOffice&logo=github)](https://github.com/dskripchenko/php-docx/actions/workflows/conformance.yml)
[![Latest Version](https://img.shields.io/packagist/v/dskripchenko/php-docx?logo=packagist&logoColor=white)](https://packagist.org/packages/dskripchenko/php-docx)
[![Total Downloads](https://img.shields.io/packagist/dt/dskripchenko/php-docx)](https://packagist.org/packages/dskripchenko/php-docx)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](../../LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-purple.svg)](https://www.php.net)


Pure-PHP DOCX-Bibliothek (Office Open XML): **bidirektionale HTML ↔
DOCX-Konvertierung**, **fluent programmatischer Builder**,
**Variablenerkennung**, **round-trip-sicheres AST**. Keine externen
Abhängigkeiten ausser Standard-PHP-Erweiterungen.

**Read this in other languages:**
[English](../../README.md) ·
[Русский](../ru/README.md) ·
[中文](../zh/README.md) ·
**Deutsch**

---

## Inhaltsverzeichnis

- [Funktionen](#funktionen)
- [Anforderungen](#anforderungen)
- [Installation](#installation)
- [Schnellstart](#schnellstart)
  - [HTML → DOCX](#1-html--docx)
  - [Programmatischer Builder](#2-programmatischer-builder)
  - [DOCX → HTML / AST](#3-docx--html--ast)
- [HTML → DOCX](#html--docx-1)
- [Builder-API](#builder-api)
- [DOCX → HTML (Reader)](#docx--html-reader)
- [Kopf-/Fußzeilen & Wasserzeichen](#kopf-fußzeilen--wasserzeichen)
- [Variablenerkennung](#variablenerkennung)
- [Längen-Helper](#längen-helper)
- [AST-Übersicht](#ast-übersicht)
- [Round-Trip](#round-trip)
- [Architektur](#architektur)
- [Entwicklung](#entwicklung)
- [Lizenz](#lizenz)

---

## Funktionen

- **HTML → DOCX Writer** — vollständiger Satz typischer Layout-Elemente
  (Absätze/Überschriften/Tabellen/Listen/Bilder/Links/Felder), Auflösung
  von Inline-Styles, eigenes Heading-Style-Registry.
- **DOCX → HTML Reader** — analysiert beliebige Dokumente aus Word /
  Pages / LibreOffice in ein typisiertes AST und serialisiert zurück in
  HTML mit Inline-Styles. Style-Kaskade (docDefaults → benannt →
  direkt), Theme-Farben, Numbering-Rekonstruktion,
  vMerge/gridSpan-Auflösung, Wasserzeichen-Erkennung (VML + DrawingML).
- **Fluent programmatischer Builder** — `DocumentBuilder` mit
  Closure-Scopes für verschachtelte Strukturen (Tabellen, Listen,
  Kopfzeilen).
- **Variablenerkennung** — MERGEFIELD, SDT-Inhaltssteuerelemente,
  konfigurierbare Textmuster (`{{x}}`, `${x}`, `%x%`).
- **Multi-Header/Footer** — default / Titelseite / gerade Seiten,
  inklusive automatischem `<w:titlePg/>` und `<w:evenAndOddHeaders/>`.
- **Round-Trip-sicher** — DOCX lesen → AST → DOCX schreiben liefert ein
  valides Dokument; byteweise Unterschiede beschränken sich auf
  Whitespace und Reihenfolge.
- **PHP 8.2+** — `readonly` Value-Objects, benannte Argumente,
  Constructor-Promotion, Enums.
- **Null Composer-Abhängigkeiten.**

### Nicht im Umfang

Änderungen verfolgen, Kommentare, eingebettete Diagramme, OLE-Objekte,
Fuß-/Endnoten, SmartArt, Formeln (OMML), Formularfelder,
benutzerdefinierte XML-Parts.

---

## Anforderungen

- PHP **8.2+**
- `ext-zip`, `ext-dom`, `ext-mbstring`

---

## Installation

```bash
composer require dskripchenko/php-docx
```

---

## Schnellstart

### 1. HTML → DOCX

```php
use Dskripchenko\PhpDocx\Html\Converter;
use Dskripchenko\PhpDocx\Writer\Word2007Writer;

$html = <<<HTML
<h1>Rechnung Nr. 42</h1>
<p>Gesamt: <strong>500 USD</strong></p>
<table>
  <tr><th>Artikel</th><th>Menge</th></tr>
  <tr><td>Widget</td><td>2</td></tr>
</table>
<p>Seite <page-number/> von <page-total/></p>
HTML;

$doc = (new Converter)->fromHtml($html);
file_put_contents('invoice.docx', (new Word2007Writer)->write($doc));
```

### 2. Programmatischer Builder

```php
use Dskripchenko\PhpDocx\Build\DocumentBuilder;
use Dskripchenko\PhpDocx\Element\ListFormat;

DocumentBuilder::new()
    ->watermark('ENTWURF')
    ->header(fn ($h) => $h->paragraph('Acme GmbH'))
    ->footer(fn ($f) => $f->paragraph(fn ($p) => $p
        ->text('Seite ')->pageNumber()->text(' von ')->totalPages()
    ))
    ->heading(1, 'Rechnung Nr. 42')
    ->paragraph(fn ($p) => $p
        ->text('Kunde: ')->bold('Acme GmbH')
        ->lineBreak()
        ->text('ID: ')->mergeField('CustomerID')
    )
    ->table(fn ($t) => $t
        ->columns(fn ($c) => $c->widthCm(8), fn ($c) => $c->widthCm(3))
        ->headerRow(['Artikel', 'Menge'])
        ->row(['Widget', '2'])
    )
    ->orderedList(fn ($l) => $l
        ->format(ListFormat::LowerLetter)
        ->item('Zahlungsziel 30 Tage')
        ->item('Versand inklusive')
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

Das Eingabe-HTML darf **nur Inline-Styles** verwenden (keine
`<style>`-Blöcke). Wenn nötig, einen CSS-Inliner vorschalten.

### Unterstützte Elemente

| Kategorie | HTML-Tags |
|---|---|
| Textblöcke | `<p>`, `<h1..h6>`, `<div>`, `<pre>`, `<blockquote>` |
| Inline-Marken | `<strong>/<b>`, `<em>/<i>`, `<u>`, `<s>/<del>`, `<sup>`, `<sub>`, `<mark>` |
| Code/Maschinen­schrift | `<code>`, `<kbd>`, `<samp>`, `<var>`, `<cite>`, `<dfn>`, `<q>`, `<small>` |
| Links | `<a href>` extern, `<a href="#anchor">` intern, `<a id>` Lesezeichen |
| Bilder | `<img src="data:image/...;base64,...">` |
| Tabellen | `<table>`, `<thead>/<tbody>`, `<tr>`, `<th>/<td>`, `<colgroup>/<col>`, `<caption>`, `colspan`, `rowspan` |
| Listen | `<ul>`, `<ol type="a/A/i/I" start="N">`, `<li value="N">`, `<dl>/<dt>/<dd>` |
| Eigene Tags | `<page-number/>`, `<page-total/>`, `<current-date format="...">`, `<page-break>` |
| Layout | `<hr>`, `<br>`, `<figure>/<figcaption>` |

### Inline-Styles

Der Converter versteht `style="…"`-Eigenschaften:

- Run-Ebene: `font-family`, `font-size`, `font-weight`, `font-style`,
  `text-decoration`, `color`, `background-color`
- Absatz-Ebene: `text-align`, `margin`, `text-indent`, `line-height`,
  `border`, `padding`
- Tabellen-Ebene: `width`, `border`, `border-collapse`
- Zellen-Ebene: `width`, `padding`, `border`, `vertical-align`,
  `background-color`

### Eigene Tags

```html
<p>Seite <page-number/> von <page-total/></p>
<p>Erstellt am <current-date format="dd.MM.yyyy"/></p>
```

Diese werden zu OOXML-Feldcodes (`<w:fldSimple w:instr="PAGE">`).

### Eigene Heading-Styles

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

## Builder-API

Der `Build`-Namespace bietet eine fluent API, um DOCX-Dokumente Block für
Block zusammenzubauen. Ergebnis: dasselbe immutable AST wie bei der
HTML-Pipeline.

### DocumentBuilder

Einstiegspunkt. Sammelt Body, Kopf-/Fußzeilen, Wasserzeichen,
Seiteneinrichtung.

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
    ->watermark('VERTRAULICH')
    ->heading(1, 'Bericht')
    ->paragraph('Inhalt')
    ->build();           // → Document AST

$bytes = DocumentBuilder::new()->paragraph('Hallo')->toBytes();
$count = DocumentBuilder::new()->paragraph('Hallo')->toFile('out.docx');
```

### ParagraphBuilder

Innerhalb `->paragraph(fn ($p) => …)`:

```php
->paragraph(fn ($p) => $p
    ->text('normal ')
    ->bold('fett ')
    ->italic('kursiv ')
    ->underline('unter ')
    ->strike('durch ')
    ->sup('hoch')->text('gestellt ')
    ->sub('tief')->text('gestellt ')
    ->styled('rot', fn ($s) => $s->color('ff0000')->bold())
    ->lineBreak()
    ->link('https://example.com', 'Webseite')
    ->internalLink('section1', 'zu Abschnitt 1')
    ->bookmark('anchor1', 'Anker')
    ->pageNumber()
    ->totalPages()
    ->currentDate('yyyy-MM-dd')
    ->mergeField('CustomerName')
    ->image($img)
    ->imageFromFile('/path/to/logo.png', widthPx: 150, altText: 'Logo')
)
```

Absatz-Stil:

```php
->paragraph(fn ($p) => $p
    ->alignCenter()           // oder alignRight()/alignJustify()
    ->indentMm(left: 20, firstLine: 10)
    ->spacingPt(before: 6, after: 12)
    ->text('Eingerückt')
)
```

### TableBuilder

```php
use Dskripchenko\PhpDocx\Build\{TableBuilder, TableRowBuilder, TableCellBuilder, ColumnBuilder};

->table(fn (TableBuilder $t) => $t
    ->caption('Umsatz 2026')
    ->column(fn (ColumnBuilder $c) => $c->widthCm(6))
    ->column(fn (ColumnBuilder $c) => $c->widthCm(3))
    ->widthPercent(100)
    ->alignCenter()
    ->cellMarginsMm(2)
    ->headerRow(['Artikel', 'Preis'])
    ->row(['Apfel', '10 USD'])
    ->row(fn (TableRowBuilder $r) => $r
        ->cell('Banane')
        ->cell(fn (TableCellBuilder $c) => $c
            ->backgroundColor('ffeb3b')
            ->valignCenter()
            ->paragraph(fn ($p) => $p->bold('20 USD'))
        )
    )
)
```

Zellen-Spans:

```php
->row(fn ($r) => $r
    ->cell(fn ($c) => $c->gridSpan(2)->paragraph('Breite Kopfzeile'))
)
->row(fn ($r) => $r
    ->cell(fn ($c) => $c->rowSpan(2)->paragraph('Hoch'))
    ->cell('rechts')
)
```

### ListBuilder

```php
use Dskripchenko\PhpDocx\Build\ListBuilder;
use Dskripchenko\PhpDocx\Element\ListFormat;

->bulletList(fn (ListBuilder $l) => $l
    ->item('Erstens')
    ->item('Zweitens', fn ($n) => $n
        ->item('Verschachtelt A')
        ->item('Verschachtelt B')
    )
)

->orderedList(fn (ListBuilder $l) => $l
    ->format(ListFormat::LowerLetter)   // a, b, c
    ->startAt(3)
    ->item('beginnt bei "c"')
)
```

### RunStyleBuilder

Verwendet in `->styled(text, fn (RunStyleBuilder) => …)` oder
standalone über `RunStyleBuilder::new()->…->build()`.

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

### Längen-Helper

Konvertierung gängiger Einheiten in OOXML-native Twips (1 Twip = 1/20
Punkt).

```php
use Dskripchenko\PhpDocx\Build\Length;

Length::pt(12);     // 240
Length::mm(20);     // 1134
Length::cm(2.5);    // 1417
Length::inch(0.5);  // 720
Length::px(100);    // 1500  (CSS px @ 96 DPI)
```

Die meisten Builder bieten einheiten-bewusste Shortcuts:

- TableBuilder: `widthPt/widthMm/widthCm/widthInches`,
  `cellMarginsMm/cellMarginsPt`
- TableCellBuilder: `widthPt/Mm/Cm/Inches`, `paddingMm/Pt/Cm/Inches`
- ColumnBuilder: `widthPt/Mm/Cm/Inches/Px`
- ParagraphBuilder: `indentMm/Cm/Pt/Inches`, `spacingPt/Mm`
- RunStyleBuilder: `fontSizePt`

---

## DOCX → HTML (Reader)

### High-Level: DocxReader

```php
use Dskripchenko\PhpDocx\Reader\DocxReader;

$document = (new DocxReader)->read(file_get_contents('input.docx'));
// → Document (AST)
```

Führt die komplette Pipeline aus: Paket entpacken → Styles auflösen →
Body/Header/Footer parsen → vMerge/Listen rekonstruieren → Bilder
extrahieren → Wasserzeichen erkennen → Seiteneinrichtung.

### Low-Level: DocxPackageReader

Wenn die rohen OOXML-Parts benötigt werden:

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

Die HTML-Ausgabe verwendet nur Inline-Styles und ist über
`Html\Converter::fromHtml($imported->bodyHtml)` wieder in dieselbe
Bibliothek ladbar.

---

## Kopf-/Fußzeilen & Wasserzeichen

Pro Abschnitt werden drei Header-/Footer-Typen unterstützt: `default`,
`first` (Titelseite), `even` (gerade Seiten). Word wählt automatisch
den richtigen anhand der Seitennummer.

```php
DocumentBuilder::new()
    ->header(fn ($h) => $h->paragraph('Standard-Kopfzeile'))
    ->firstHeader(fn ($h) => $h->paragraph('Titelseite'))
    ->evenHeader(fn ($h) => $h->paragraph(fn ($p) => $p
        ->text('Seite ')->pageNumber()
    ))
    ->footer(fn ($f) => $f->paragraph('© 2026 Acme'))
    ->firstFooter(fn ($f) => $f->paragraph('Vertraulich'))
    ->evenFooter(fn ($f) => $f->paragraph('Gerade Seite'))
    ->paragraph('Inhalt')
    ->toFile('with-headers.docx');
```

Der Writer erledigt automatisch:
- gibt `<w:titlePg/>` in `sectPr` aus, wenn Titel-Header/Footer gesetzt ist
- gibt `word/settings.xml` mit `<w:evenAndOddHeaders/>` aus, wenn ein
  Even-Header/Footer gesetzt ist

### Wasserzeichen

```php
DocumentBuilder::new()
    ->watermark('ENTWURF')
    ->paragraph('Inhalt')
    ->toFile('with-watermark.docx');
```

Wird als um 45° gedrehte VML-Textform auf jeder Seite gerendert.

---

## Variablenerkennung

Durchsucht ein importiertes DOCX nach drei Arten von Variablen:

1. **MERGEFIELD** — Word-Mailmerge-nativ, sowohl simple `<w:fldSimple>`
   als auch komplexe `<w:fldChar>`-Form.
2. **SDT-Inhaltssteuerelemente** — `<w:sdt>` mit
   `<w:tag w:val="...">`.
3. **Textmuster** — konfigurierbare Regex (Standard: `{{name}}`,
   `${name}`, `%name%`).

```php
use Dskripchenko\PhpDocx\Reader\VariableDetector;

$pkg = (new DocxPackageReader)->read($bytes);
$detector = new VariableDetector;     // Standard
// Oder mit eigenen Regex:
$detector = new VariableDetector(['/\[\[(\w+)\]\]/']);

$variables = $detector->detect($pkg);
foreach ($variables as $v) {
    echo "{$v->name} ({$v->source->value})";
    echo " placeholder='{$v->placeholder}'";
    echo " sample='{$v->sampleValue}'\n";
}
```

Die Erkennung läuft über `Body + alle Header + alle Footer`. Ergebnisse
werden nach `(Quelle, Name)` dedupliziert.

---

## Längen-Helper

Umrechnungstabelle:

| Einheit | Twips | Pt | Hinweis |
|---|---|---|---|
| 1 Twip | 1 | 0.05 | OOXML-nativ |
| 1 pt | 20 | 1 | Typografie |
| 1 mm | ~57 | 2.83 | metrisch |
| 1 cm | ~567 | 28.35 | metrisch |
| 1 Zoll | 1440 | 72 | imperial |
| 1 px | 15 | 0.75 | CSS bei 96 DPI |

---

## AST-Übersicht

Alle Elemente leben unter `Dskripchenko\PhpDocx\Element`.

| Element | Typ | Hinweise |
|---|---|---|
| `Document` | root | `{ section: Section, watermarkText: ?string }` |
| `Section` | container | `{ body, header, footer, pageSetup, firstHeader, firstFooter, evenHeader, evenFooter }` |
| `Paragraph` | BlockElement | `{ children: InlineElement[], style, headingLevel: ?int }` |
| `Run` | InlineElement | `{ text: string, style: RunStyle }` |
| `Hyperlink` | InlineElement | `{ href: ?string, anchor: ?string, children }` |
| `Bookmark` | InlineElement | `{ name: string, children }` |
| `Image` | beides | `{ binary, format, widthEmu, heightEmu, altText }` |
| `Field` | InlineElement | `{ instruction: string, style: RunStyle }` |
| `LineBreak`, `PageBreak`, `HorizontalRule` | beides | Marker-Elemente |
| `Table` | BlockElement | `{ rows: TableRow[], style, caption, gridColumnsTwips }` |
| `TableRow` | element | `{ cells: TableCell[], isHeader, heightTwips }` |
| `TableCell` | element | `{ children: BlockElement[], style: CellStyle }` |
| `ListNode` | BlockElement | `{ items: ListItem[], ordered, format, startAt }` |
| `ListItem` | element | `{ children: InlineElement[], nestedList: ?ListNode }` |

Styles liegen unter `Dskripchenko\PhpDocx\Style`:

- `RunStyle` — Schrift, Gewicht, Kursiv, Farbe, Grösse, Highlight, …
- `ParagraphStyle` — Ausrichtung, Einzüge, Abstände, Rahmen
- `CellStyle` — Breite, Padding, Rahmen, vertikale Ausrichtung,
  gridSpan, rowSpan
- `TableStyle` — Breite, Rahmen, Ausrichtung, Zellenränder, Layout
- `PageSetup`, `PaperSize`, `Orientation`, `Alignment`, `VerticalAlign`,
  `BorderStyle`, `Border`, `BorderSet`

---

## Round-Trip

```php
$bytes1 = file_get_contents('original.docx');
$doc = (new DocxReader)->read($bytes1);
$bytes2 = (new Word2007Writer)->write($doc);
file_put_contents('roundtrip.docx', $bytes2);
```

Die Bibliothek garantiert **semantische** Round-Trip-Sicherheit, keine
Byte-Gleichheit — Inhalt, Struktur und Stil bleiben erhalten, aber
XML-Reihenfolge und Whitespace können sich unterscheiden.

Im Round-Trip enthalten:
- Absätze/Überschriften mit allen Run-Formatierungen
- Tabellen mit `vMerge`/`gridSpan`-Rekonstruktion
- Listen (Aufzählung/Dezimal/Buchstabe/Römisch) mit beliebiger
  Verschachtelung
- Bilder mit EMU-Grössen und Alt-Text
- Hyperlinks (extern + interne Anker) und Lesezeichen
- Kopf-/Fusszeilen (default/first/even) und Wasserzeichen
- Feldcodes (PAGE, NUMPAGES, DATE, MERGEFIELD)
- Seiteneinrichtung (Grösse, Orientierung, Ränder)

Nicht-unterstützte Features werden still verworfen (Fussnoten,
Kommentare, Formeln usw.).

---

## Architektur

```
HTML (inline styles)
       │
       ▼  Html\Converter
   Document (AST)  ◀──── DocumentBuilder (programmatisch)
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

Dasselbe `Document`-AST wird von HTML-Konvertierung, programmatischem
Aufbau und DOCX-Lesen geteilt — jeder Eintritts-/Austrittspunkt
arbeitet mit typisierten Value-Objects.

---

## Entwicklung

```bash
composer install
composer test       # phpunit-Suite (~340 Tests)
composer stan       # phpstan Level 8
```

---

## Lizenz

MIT — siehe [LICENSE](../../LICENSE).
