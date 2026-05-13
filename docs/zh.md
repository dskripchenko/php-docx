# dskripchenko/php-docx

纯 PHP 实现的 DOCX（Office Open XML）库：**HTML ↔ DOCX 双向转换**、
**流式编程构造器**、**变量检测**、**往返安全的 AST**。除标准 PHP 扩展
外，无任何外部依赖。

**Read this in other languages:**
[English](../README.md) ·
[Русский](ru.md) ·
**中文** ·
[Deutsch](de.md)

---

## 目录

- [特性](#特性)
- [环境要求](#环境要求)
- [安装](#安装)
- [快速开始](#快速开始)
  - [HTML → DOCX](#1-html--docx)
  - [编程构造器](#2-编程构造器)
  - [DOCX → HTML / AST](#3-docx--html--ast)
- [HTML → DOCX](#html--docx-1)
- [编程构造器 API](#编程构造器-api)
- [DOCX → HTML（Reader）](#docx--html-reader)
- [页眉、页脚与水印](#页眉页脚与水印)
- [变量检测](#变量检测)
- [长度辅助函数](#长度辅助函数)
- [AST 概览](#ast-概览)
- [往返转换](#往返转换)
- [架构](#架构)
- [开发](#开发)
- [许可证](#许可证)

---

## 特性

- **HTML → DOCX writer** — 支持典型布局元素（段落/标题/表格/列表/图片/
  链接/字段），解析 inline 样式，自定义标题样式注册表。
- **DOCX → HTML reader** — 解析来自 Word / Pages / LibreOffice 的任意
  文档为类型化 AST，然后序列化回带 inline 样式的 HTML。完整的样式继承
  链（docDefaults → 命名样式 → 直接覆盖）、主题色、numbering 重建、
  vMerge/gridSpan 合并恢复、水印检测（VML + DrawingML）。
- **流式编程构造器** — `DocumentBuilder`，使用闭包作用域处理嵌套结构
  （表格、列表、页眉）。
- **变量检测** — MERGEFIELD、SDT 内容控件、可配置的文本模式
  （`{{x}}`、`${x}`、`%x%`）。
- **多页眉/页脚** — default / 首页 / 偶数页变体，自动处理
  `<w:titlePg/>` 与 `<w:evenAndOddHeaders/>`。
- **往返安全** — 读 DOCX → AST → 写 DOCX 得到有效文档；字节级别的差异
  仅限于空白和顺序。
- **PHP 8.2+** — `readonly` 值对象、命名参数、构造函数提升、enum。
- **零 composer 依赖。**

### 超出范围

修订记录、批注、嵌入图表、OLE 对象、脚注/尾注、SmartArt、数学公式
（OMML）、表单字段、自定义 XML 部件。

---

## 环境要求

- PHP **8.2+**
- `ext-zip`、`ext-dom`、`ext-mbstring`

---

## 安装

```bash
composer require dskripchenko/php-docx
```

---

## 快速开始

### 1. HTML → DOCX

```php
use Dskripchenko\PhpDocx\Html\Converter;
use Dskripchenko\PhpDocx\Writer\Word2007Writer;

$html = <<<HTML
<h1>发票 #42</h1>
<p>合计：<strong>500 USD</strong></p>
<table>
  <tr><th>项目</th><th>数量</th></tr>
  <tr><td>小部件</td><td>2</td></tr>
</table>
<p>第 <page-number/> 页，共 <page-total/> 页</p>
HTML;

$doc = (new Converter)->fromHtml($html);
file_put_contents('invoice.docx', (new Word2007Writer)->write($doc));
```

### 2. 编程构造器

```php
use Dskripchenko\PhpDocx\Build\DocumentBuilder;
use Dskripchenko\PhpDocx\Element\ListFormat;

DocumentBuilder::new()
    ->watermark('草稿')
    ->header(fn ($h) => $h->paragraph('Acme 公司'))
    ->footer(fn ($f) => $f->paragraph(fn ($p) => $p
        ->text('第 ')->pageNumber()->text(' 页，共 ')->totalPages()
    ))
    ->heading(1, '发票 #42')
    ->paragraph(fn ($p) => $p
        ->text('客户：')->bold('Acme 公司')
        ->lineBreak()
        ->text('ID：')->mergeField('CustomerID')
    )
    ->table(fn ($t) => $t
        ->columns(fn ($c) => $c->widthCm(8), fn ($c) => $c->widthCm(3))
        ->headerRow(['项目', '数量'])
        ->row(['小部件', '2'])
    )
    ->orderedList(fn ($l) => $l
        ->format(ListFormat::LowerLetter)
        ->item('30 天付款条款')
        ->item('免费配送')
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

输入 HTML 必须**仅使用 inline 样式**（不接受 `<style>` 块）。如果需要，
请在上游使用 CSS-inliner。

### 支持的元素

| 类别 | HTML 标签 |
|---|---|
| 文本块 | `<p>`、`<h1..h6>`、`<div>`、`<pre>`、`<blockquote>` |
| 行内标记 | `<strong>/<b>`、`<em>/<i>`、`<u>`、`<s>/<del>`、`<sup>`、`<sub>`、`<mark>` |
| 等宽/代码 | `<code>`、`<kbd>`、`<samp>`、`<var>`、`<cite>`、`<dfn>`、`<q>`、`<small>` |
| 链接 | `<a href>` 外部，`<a href="#anchor">` 内部，`<a id>` 书签 |
| 图片 | `<img src="data:image/...;base64,...">` |
| 表格 | `<table>`、`<thead>/<tbody>`、`<tr>`、`<th>/<td>`、`<colgroup>/<col>`、`<caption>`、`colspan`、`rowspan` |
| 列表 | `<ul>`、`<ol type="a/A/i/I" start="N">`、`<li value="N">`、`<dl>/<dt>/<dd>` |
| 自定义标签 | `<page-number/>`、`<page-total/>`、`<current-date format="...">`、`<page-break>` |
| 布局 | `<hr>`、`<br>`、`<figure>/<figcaption>` |

### Inline 样式

转换器理解 `style="…"` 属性：

- Run 级别：`font-family`、`font-size`、`font-weight`、`font-style`、
  `text-decoration`、`color`、`background-color`
- Paragraph 级别：`text-align`、`margin`、`text-indent`、`line-height`、
  `border`、`padding`
- Table 级别：`width`、`border`、`border-collapse`
- Cell 级别：`width`、`padding`、`border`、`vertical-align`、
  `background-color`

### 自定义标签

```html
<p>第 <page-number/> 页，共 <page-total/> 页</p>
<p>生成于 <current-date format="dd.MM.yyyy"/></p>
```

它们会变成 OOXML 字段代码（`<w:fldSimple w:instr="PAGE">`）。

### 自定义标题样式

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

## 编程构造器 API

`Build` 命名空间提供 fluent API，按块组装 DOCX 文档，最终生成与 HTML
pipeline 相同的不可变 AST。

### DocumentBuilder

入口点。积累 body、页眉/页脚、水印、页面设置。

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
    ->watermark('机密')
    ->heading(1, '报告')
    ->paragraph('正文')
    ->build();           // → Document AST

$bytes = DocumentBuilder::new()->paragraph('你好')->toBytes();
$count = DocumentBuilder::new()->paragraph('你好')->toFile('out.docx');
```

### ParagraphBuilder

在 `->paragraph(fn ($p) => …)` 内：

```php
->paragraph(fn ($p) => $p
    ->text('普通 ')
    ->bold('粗体 ')
    ->italic('斜体 ')
    ->underline('下划线 ')
    ->strike('删除线 ')
    ->sup('上')->text('标 ')
    ->sub('下')->text('标 ')
    ->styled('红色', fn ($s) => $s->color('ff0000')->bold())
    ->lineBreak()
    ->link('https://example.com', '网站')
    ->internalLink('section1', '前往第 1 节')
    ->bookmark('anchor1', '锚点')
    ->pageNumber()
    ->totalPages()
    ->currentDate('yyyy-MM-dd')
    ->mergeField('CustomerName')
    ->image($img)
    ->imageFromFile('/path/to/logo.png', widthPx: 150, altText: '徽标')
)
```

段落级别样式：

```php
->paragraph(fn ($p) => $p
    ->alignCenter()           // 或 alignRight() / alignJustify()
    ->indentMm(left: 20, firstLine: 10)
    ->spacingPt(before: 6, after: 12)
    ->text('带缩进与间距')
)
```

### TableBuilder

```php
use Dskripchenko\PhpDocx\Build\{TableBuilder, TableRowBuilder, TableCellBuilder, ColumnBuilder};

->table(fn (TableBuilder $t) => $t
    ->caption('2026 年销售')
    ->column(fn (ColumnBuilder $c) => $c->widthCm(6))
    ->column(fn (ColumnBuilder $c) => $c->widthCm(3))
    ->widthPercent(100)
    ->alignCenter()
    ->cellMarginsMm(2)
    ->headerRow(['项目', '价格'])
    ->row(['苹果', '10 USD'])
    ->row(fn (TableRowBuilder $r) => $r
        ->cell('香蕉')
        ->cell(fn (TableCellBuilder $c) => $c
            ->backgroundColor('ffeb3b')
            ->valignCenter()
            ->paragraph(fn ($p) => $p->bold('20 USD'))
        )
    )
)
```

跨行/跨列：

```php
->row(fn ($r) => $r
    ->cell(fn ($c) => $c->gridSpan(2)->paragraph('宽表头'))
)
->row(fn ($r) => $r
    ->cell(fn ($c) => $c->rowSpan(2)->paragraph('高单元格'))
    ->cell('右侧')
)
```

### ListBuilder

```php
use Dskripchenko\PhpDocx\Build\ListBuilder;
use Dskripchenko\PhpDocx\Element\ListFormat;

->bulletList(fn (ListBuilder $l) => $l
    ->item('第一项')
    ->item('第二项', fn ($n) => $n
        ->item('嵌套 A')
        ->item('嵌套 B')
    )
)

->orderedList(fn (ListBuilder $l) => $l
    ->format(ListFormat::LowerLetter)   // a, b, c
    ->startAt(3)
    ->item('从 "c" 开始')
)
```

### RunStyleBuilder

在 `->styled(text, fn (RunStyleBuilder) => …)` 内使用，或通过
`RunStyleBuilder::new()->…->build()` 独立使用。

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

### 长度辅助函数

将常用单位转换为 OOXML 原生 twips（1 twip = 1/20 pt）。

```php
use Dskripchenko\PhpDocx\Build\Length;

Length::pt(12);     // 240
Length::mm(20);     // 1134
Length::cm(2.5);    // 1417
Length::inch(0.5);  // 720
Length::px(100);    // 1500（CSS px @ 96 DPI）
```

大多数构造器都暴露了单位感知快捷方法：

- TableBuilder：`widthPt/widthMm/widthCm/widthInches`、
  `cellMarginsMm/cellMarginsPt`
- TableCellBuilder：`widthPt/Mm/Cm/Inches`、`paddingMm/Pt/Cm/Inches`
- ColumnBuilder：`widthPt/Mm/Cm/Inches/Px`
- ParagraphBuilder：`indentMm/Cm/Pt/Inches`、`spacingPt/Mm`
- RunStyleBuilder：`fontSizePt`

---

## DOCX → HTML（Reader）

### 高级：DocxReader

```php
use Dskripchenko\PhpDocx\Reader\DocxReader;

$document = (new DocxReader)->read(file_get_contents('input.docx'));
// → Document (AST)
```

运行完整 pipeline：解包 → 解析样式 → 解析 body/页眉/页脚 →
vMerge/列表重建 → 提取图片 → 检测水印 → 页面设置。

### 低级：DocxPackageReader

如果需要原始 OOXML 部件：

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

### Serializer：AST → HTML

```php
use Dskripchenko\PhpDocx\Html\Serializer;

$imported = (new Serializer)->serialize($document, $variables);

// ImportedDocument：
$imported->bodyHtml;
$imported->headerHtml;
$imported->footerHtml;
$imported->watermarkText;
$imported->pageSettings;
$imported->variables;
$imported->media;
```

HTML 输出仅使用 inline 样式，可通过
`Html\Converter::fromHtml($imported->bodyHtml)` 重新加载到同一个库。

---

## 页眉、页脚与水印

每个节支持三种页眉/页脚类型：`default`、`first`（封面）、
`even`（偶数页）。Word 会根据页码自动选择正确的版本。

```php
DocumentBuilder::new()
    ->header(fn ($h) => $h->paragraph('默认页眉'))
    ->firstHeader(fn ($h) => $h->paragraph('封面'))
    ->evenHeader(fn ($h) => $h->paragraph(fn ($p) => $p
        ->text('第 ')->pageNumber()->text(' 页')
    ))
    ->footer(fn ($f) => $f->paragraph('© 2026 Acme'))
    ->firstFooter(fn ($f) => $f->paragraph('机密'))
    ->evenFooter(fn ($f) => $f->paragraph('偶数页页脚'))
    ->paragraph('正文')
    ->toFile('with-headers.docx');
```

Writer 会自动：
- 当设置了首页页眉/页脚时，在 `sectPr` 中输出 `<w:titlePg/>`
- 当设置了偶数页页眉/页脚时，输出带 `<w:evenAndOddHeaders/>` 的
  `word/settings.xml`

### 水印

```php
DocumentBuilder::new()
    ->watermark('草稿')
    ->paragraph('正文')
    ->toFile('with-watermark.docx');
```

呈现为旋转 45° 的 VML 文本形状，出现在每一页。

---

## 变量检测

扫描已导入的 DOCX，查找三种变量：

1. **MERGEFIELD** — Word 邮件合并原生字段，简单 `<w:fldSimple>` 与
   复合 `<w:fldChar>` 两种形式均可。
2. **SDT 内容控件** — 带 `<w:tag w:val="...">` 的 `<w:sdt>`。
3. **文本模式** — 可配置的正则（默认：`{{name}}`、`${name}`、`%name%`）。

```php
use Dskripchenko\PhpDocx\Reader\VariableDetector;

$pkg = (new DocxPackageReader)->read($bytes);
$detector = new VariableDetector;     // 默认
// 或带自定义正则：
$detector = new VariableDetector(['/\[\[(\w+)\]\]/']);

$variables = $detector->detect($pkg);
foreach ($variables as $v) {
    echo "{$v->name} ({$v->source->value})";
    echo " placeholder='{$v->placeholder}'";
    echo " sample='{$v->sampleValue}'\n";
}
```

检测覆盖 `body + 所有页眉 + 所有页脚`。结果按 `(source, name)` 去重。

---

## 长度辅助函数

转换表：

| 单位 | Twips | Pt | 备注 |
|---|---|---|---|
| 1 twip | 1 | 0.05 | OOXML 原生 |
| 1 pt | 20 | 1 | 排版 |
| 1 mm | ~57 | 2.83 | 公制 |
| 1 cm | ~567 | 28.35 | 公制 |
| 1 inch | 1440 | 72 | 英制 |
| 1 px | 15 | 0.75 | CSS @ 96 DPI |

---

## AST 概览

所有元素位于 `Dskripchenko\PhpDocx\Element` 命名空间。

| 元素 | 类型 | 备注 |
|---|---|---|
| `Document` | root | `{ section: Section, watermarkText: ?string }` |
| `Section` | container | `{ body, header, footer, pageSetup, firstHeader, firstFooter, evenHeader, evenFooter }` |
| `Paragraph` | BlockElement | `{ children: InlineElement[], style, headingLevel: ?int }` |
| `Run` | InlineElement | `{ text: string, style: RunStyle }` |
| `Hyperlink` | InlineElement | `{ href: ?string, anchor: ?string, children }` |
| `Bookmark` | InlineElement | `{ name: string, children }` |
| `Image` | 两者 | `{ binary, format, widthEmu, heightEmu, altText }` |
| `Field` | InlineElement | `{ instruction: string, style: RunStyle }` |
| `LineBreak`、`PageBreak`、`HorizontalRule` | 两者 | 标记元素 |
| `Table` | BlockElement | `{ rows: TableRow[], style, caption, gridColumnsTwips }` |
| `TableRow` | element | `{ cells: TableCell[], isHeader, heightTwips }` |
| `TableCell` | element | `{ children: BlockElement[], style: CellStyle }` |
| `ListNode` | BlockElement | `{ items: ListItem[], ordered, format, startAt }` |
| `ListItem` | element | `{ children: InlineElement[], nestedList: ?ListNode }` |

样式位于 `Dskripchenko\PhpDocx\Style`：

- `RunStyle` — 字体、weight、italic、color、size、highlight 等
- `ParagraphStyle` — alignment、缩进、间距、边框
- `CellStyle` — width、padding、边框、valign、gridSpan、rowSpan
- `TableStyle` — width、边框、对齐、单元格边距、布局
- `PageSetup`、`PaperSize`、`Orientation`、`Alignment`、`VerticalAlign`、
  `BorderStyle`、`Border`、`BorderSet`

---

## 往返转换

```php
$bytes1 = file_get_contents('original.docx');
$doc = (new DocxReader)->read($bytes1);
$bytes2 = (new Word2007Writer)->write($doc);
file_put_contents('roundtrip.docx', $bytes2);
```

本库的目标是 **语义** 往返安全，而非字节相等 — 内容、结构与样式得以
保留，但 XML 顺序与空白可能不同。

在范围内的往返特性：
- 段落/标题及全部 run 格式
- 带 `vMerge`/`gridSpan` 重建的表格
- 任意嵌套的列表（项目符号/十进制/字母/罗马数字）
- 带 EMU 尺寸与 alt 文本的图片
- 超链接（外部 + 内部锚点）与书签
- 页眉/页脚（default/first/even）与水印
- 字段代码（PAGE、NUMPAGES、DATE、MERGEFIELD）
- 页面设置（尺寸、方向、边距）

超出范围的特性会被静默丢弃（脚注、批注、公式等）。

---

## 架构

```
HTML (inline styles)
       │
       ▼  Html\Converter
   Document (AST)  ◀──── DocumentBuilder (编程)
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

HTML 转换、编程构造与 DOCX 读取共享同一个 `Document` AST — 每个输入/
输出点都操作类型化的值对象。

---

## 开发

```bash
composer install
composer test       # phpunit 测试套件（约 340 个测试）
composer stan       # phpstan level 8
```

---

## 许可证

MIT — 参见 [LICENSE](../LICENSE)。
