<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Html;

use Dskripchenko\PhpDocx\Document;
use Dskripchenko\PhpDocx\Element\BlockElement;
use Dskripchenko\PhpDocx\Element\Bookmark;
use Dskripchenko\PhpDocx\Element\Field;
use Dskripchenko\PhpDocx\Element\Footnote;
use Dskripchenko\PhpDocx\Element\HorizontalRule;
use Dskripchenko\PhpDocx\Element\Hyperlink;
use Dskripchenko\PhpDocx\Element\Image;
use Dskripchenko\PhpDocx\Element\ImageFormat;
use Dskripchenko\PhpDocx\Element\InlineElement;
use Dskripchenko\PhpDocx\Element\LineBreak;
use Dskripchenko\PhpDocx\Element\ListItem;
use Dskripchenko\PhpDocx\Element\ListNode;
use Dskripchenko\PhpDocx\Element\PageBreak;
use Dskripchenko\PhpDocx\Element\Paragraph;
use Dskripchenko\PhpDocx\Element\Run;
use Dskripchenko\PhpDocx\Element\Table;
use Dskripchenko\PhpDocx\Element\TableCell;
use Dskripchenko\PhpDocx\Element\TableRow;
use Dskripchenko\PhpDocx\Reader\DetectedVariable;
use Dskripchenko\PhpDocx\Style\Alignment;
use Dskripchenko\PhpDocx\Style\Border;
use Dskripchenko\PhpDocx\Style\BorderSet;
use Dskripchenko\PhpDocx\Style\BorderStyle;
use Dskripchenko\PhpDocx\Style\CellStyle;
use Dskripchenko\PhpDocx\Style\ParagraphStyle;
use Dskripchenko\PhpDocx\Style\RunStyle;
use Dskripchenko\PhpDocx\Style\TableStyle;
use Dskripchenko\PhpDocx\Style\VerticalAlign;

/**
 * Phase 10 — AST Document → HTML + media + detected variables.
 *
 * The resulting ImportedDocument holds:
 *  - bodyHtml/headerHtml/footerHtml — HTML with inline styles (re-loadable back
 *    into the AST through Html\Converter)
 *  - watermarkText, pageSettings — the metadata
 *  - variables — the DetectedVariable list (passed in by the caller rather than
 *    computed here — the Serializer is a pure AST→HTML step)
 *  - media — the extracted image bytes for the image references in the HTML
 *
 * The image strategy: `cid:N` references in the `<img src>`, media[N] = the
 * bytes. The importer's side (printable) substitutes its own storage URLs for
 * the cid: ones.
 *
 * Twip → CSS: 1 twip = 0.05 pt, so we use pt in the inline styles.
 * EMU → px: 1 px = 9525 EMU (at 96 DPI).
 */
final class Serializer
{
    /**
     * A single line spacing, in twentieths of a point (ECMA-376).
     *
     * Untyped on purpose: typed constants appeared in PHP 8.3 while the package
     * supports 8.2 — locally on 8.5 it passes, and the build then fails while
     * parsing the file.
     */
    private const SINGLE_LINE_TWIPS = 240;

    /** How many font sizes a single line spacing costs with a typical font. */
    private const SINGLE_LINE_EM = 1.2;

    /** @var array<string, string> filename → binary */
    private array $media = [];

    private int $mediaCounter = 0;

    /**
     * @param  list<DetectedVariable>  $variables  An optional list of variables
     *         (when the caller ran VariableDetector separately).
     */
    public function serialize(Document $document, array $variables = []): ImportedDocument
    {
        $this->media = [];
        $this->mediaCounter = 0;

        $bodyHtml = $this->renderBlocks($document->section->body);
        $headerHtml = $document->section->header !== []
            ? $this->renderBlocks($document->section->header)
            : null;
        $footerHtml = $document->section->footer !== []
            ? $this->renderBlocks($document->section->footer)
            : null;
        $firstHeaderHtml = $document->section->firstHeader !== []
            ? $this->renderBlocks($document->section->firstHeader)
            : null;
        $firstFooterHtml = $document->section->firstFooter !== []
            ? $this->renderBlocks($document->section->firstFooter)
            : null;

        return new ImportedDocument(
            bodyHtml: $bodyHtml,
            headerHtml: $headerHtml,
            footerHtml: $footerHtml,
            firstHeaderHtml: $firstHeaderHtml,
            firstFooterHtml: $firstFooterHtml,
            watermarkText: $document->watermarkText,
            pageSettings: $document->section->pageSetup,
            variables: $variables,
            media: $this->media,
        );
    }

    /**
     * @param  list<BlockElement>  $blocks
     */
    private function renderBlocks(array $blocks): string
    {
        $html = '';
        foreach ($blocks as $b) {
            $html .= $this->renderBlock($b);
        }

        return $html;
    }

    private function renderBlock(BlockElement $block): string
    {
        return match (true) {
            $block instanceof Paragraph => $this->renderParagraph($block),
            $block instanceof Table => $this->renderTable($block),
            $block instanceof ListNode => $this->renderList($block),
            $block instanceof PageBreak => '<hr class="page-break"/>',
            $block instanceof HorizontalRule => '<hr/>',
            $block instanceof Image => '<p>'.$this->renderImage($block).'</p>',
            default => '',
        };
    }

    private function renderParagraph(Paragraph $p): string
    {
        $tag = $p->headingLevel !== null && $p->headingLevel >= 1 && $p->headingLevel <= 6
            ? 'h'.$p->headingLevel
            : 'p';
        $style = $this->paragraphStyleCss($p->style);
        $styleAttr = $style !== '' ? ' style="'.$this->escape($style).'"' : '';
        $inner = $this->renderInlines($p->children);
        // An empty paragraph is emitted with an &nbsp; so that Word and Pages show a blank line.
        if ($inner === '') {
            $inner = '&nbsp;';
        }

        return '<'.$tag.$styleAttr.'>'.$inner.'</'.$tag.'>';
    }

    /**
     * @param  list<InlineElement>  $inlines
     */
    private function renderInlines(array $inlines): string
    {
        $html = '';
        foreach ($inlines as $i) {
            $html .= $this->renderInline($i);
        }

        return $html;
    }

    private function renderInline(InlineElement $el): string
    {
        return match (true) {
            $el instanceof Run => $this->renderRun($el),
            $el instanceof LineBreak => '<br/>',
            $el instanceof Hyperlink => $this->renderHyperlink($el),
            $el instanceof Image => $this->renderImage($el),
            $el instanceof Bookmark => $this->renderBookmark($el),
            $el instanceof Footnote => '<span class="footnote">'.$this->escape($el->content).'</span>',
            $el instanceof Field => $this->renderField($el),
            $el instanceof PageBreak => '', // обычно split'ится в Paragraph
            default => '',
        };
    }

    private function renderRun(Run $r): string
    {
        $text = $this->escape($r->text);
        // The line breaks and the tabs are preserved as HTML entities (a browser
        // collapses the whitespace, but on a round trip back into the writer
        // they survive).
        $text = strtr($text, ["\t" => '&#9;']);

        // The semantic tags for bold/italic/u/s
        $open = '';
        $close = '';
        $s = $r->style;
        if ($s->bold) {
            $open .= '<strong>';
            $close = '</strong>'.$close;
        }
        if ($s->italic) {
            $open .= '<em>';
            $close = '</em>'.$close;
        }
        if ($s->underline) {
            $open .= '<u>';
            $close = '</u>'.$close;
        }
        if ($s->strikethrough) {
            $open .= '<s>';
            $close = '</s>'.$close;
        }
        if ($s->superscript) {
            $open .= '<sup>';
            $close = '</sup>'.$close;
        }
        if ($s->subscript) {
            $open .= '<sub>';
            $close = '</sub>'.$close;
        }
        if ($s->highlight !== null) {
            $open .= '<mark>';
            $close = '</mark>'.$close;
        }

        // The inline styles (colour/font/size/background) get a span wrapper when there are any
        $cssParts = [];
        if ($s->color !== null) {
            $cssParts[] = 'color:#'.$s->color;
        }
        if ($s->backgroundColor !== null) {
            $cssParts[] = 'background-color:#'.$s->backgroundColor;
        }
        if ($s->fontFamily !== null) {
            $cssParts[] = 'font-family:'.$this->escape($s->fontFamily);
        }
        if ($s->sizeHalfPoints !== null) {
            $cssParts[] = 'font-size:'.$this->halfPtToPt($s->sizeHalfPoints).'pt';
        }
        if ($s->letterSpacingTwips !== null) {
            $cssParts[] = 'letter-spacing:'.$this->twipsToPt($s->letterSpacingTwips).'pt';
        }
        if ($s->allCaps) {
            $cssParts[] = 'text-transform:uppercase';
        }
        if ($s->smallCaps) {
            $cssParts[] = 'font-variant:small-caps';
        }
        if ($cssParts !== []) {
            $open = '<span style="'.implode(';', $cssParts).'">'.$open;
            $close .= '</span>';
        }

        return $open.$text.$close;
    }

    private function renderHyperlink(Hyperlink $h): string
    {
        $children = $this->renderInlines($h->children);
        if ($h->isInternal()) {
            return '<a href="#'.$this->escape((string) $h->anchor).'">'.$children.'</a>';
        }
        if ($h->href === null) {
            return $children;
        }

        return '<a href="'.$this->escape($h->href).'">'.$children.'</a>';
    }

    private function renderBookmark(Bookmark $b): string
    {
        $children = $this->renderInlines($b->children);

        return '<a id="'.$this->escape($b->name).'">'.$children.'</a>';
    }

    private function renderField(Field $f): string
    {
        $instr = strtoupper(trim($f->instruction));
        if (str_starts_with($instr, 'PAGE')) {
            return '<page-number/>';
        }
        if (str_starts_with($instr, 'NUMPAGES')) {
            return '<page-total/>';
        }
        if (str_starts_with($instr, 'DATE')) {
            // we pull out the format between the quotes, when there is one
            $format = '';
            if (preg_match('/"([^"]+)"/', $f->instruction, $m) === 1) {
                $format = ' format="'.$this->escape($m[1]).'"';
            }

            return '<current-date'.$format.'/>';
        }
        if (str_starts_with($instr, 'TIME')) {
            return '<current-time/>';
        }
        if (preg_match('/^MERGEFIELD\s+([\w\.]+)/i', $f->instruction, $m) === 1) {
            return '<var data-name="'.$this->escape($m[1]).'">'.$this->escape($m[1]).'</var>';
        }
        // Default — text-placeholder.
        return '<var data-instr="'.$this->escape($f->instruction).'"></var>';
    }

    private function renderImage(Image $img): string
    {
        $this->mediaCounter++;
        $ext = $img->format->extension();
        $filename = 'img'.$this->mediaCounter.'.'.$ext;
        $this->media[$filename] = $img->binary;

        $widthPx = (int) round($img->widthEmu / 9525);
        $heightPx = (int) round($img->heightEmu / 9525);
        $alt = $img->altText !== null ? $this->escape($img->altText) : '';
        // A data: URL is used for standalone renderability; the importer
        // replaces it with an admin-storage URL.
        $src = $this->dataUrl($img->binary, $img->format);

        // The anchor's offset becomes a CSS margin. A negative one lifts the
        // object above the preceding text exactly the way Word does it: a stamp
        // and a signature are placed on top of a finished block rather than on a
        // separate line below it.
        $style = '';
        if ($img->offsetYEmu !== 0) {
            $offsetPt = round($img->offsetYEmu / 12700, 2);
            $style = ' style="margin-top:'.$offsetPt.'pt"';
        }

        return '<img src="'.$src.'" alt="'.$alt.'" width="'.$widthPx.'" height="'.$heightPx.'"'
            .$style.' data-media="'.$filename.'"/>';
    }

    private function dataUrl(string $bytes, ImageFormat $format): string
    {
        $mime = $format->mimeType();

        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }

    private function renderList(ListNode $list): string
    {
        $tag = $list->ordered ? 'ol' : 'ul';
        $attrs = '';
        if ($list->ordered) {
            $type = $this->listTypeAttr($list);
            if ($type !== null) {
                $attrs .= ' type="'.$type.'"';
            }
            if ($list->startAt !== 1) {
                $attrs .= ' start="'.$list->startAt.'"';
            }
        }
        $items = '';
        foreach ($list->items as $item) {
            $items .= $this->renderListItem($item);
        }

        return '<'.$tag.$attrs.'>'.$items.'</'.$tag.'>';
    }

    private function listTypeAttr(ListNode $list): ?string
    {
        return match ($list->effectiveFormat()) {
            \Dskripchenko\PhpDocx\Element\ListFormat::LowerLetter => 'a',
            \Dskripchenko\PhpDocx\Element\ListFormat::UpperLetter => 'A',
            \Dskripchenko\PhpDocx\Element\ListFormat::LowerRoman => 'i',
            \Dskripchenko\PhpDocx\Element\ListFormat::UpperRoman => 'I',
            default => null,
        };
    }

    private function renderListItem(ListItem $item): string
    {
        $inner = $this->renderInlines($item->children);
        $nested = $item->nestedList !== null ? $this->renderList($item->nestedList) : '';
        $css = $this->paragraphStyleCss($item->style);
        $attrs = $css !== '' ? ' style="'.$css.'"' : '';

        return '<li'.$attrs.'>'.$inner.$nested.'</li>';
    }

    private function renderTable(Table $t): string
    {
        $style = $this->tableStyleCss($t->style);
        $styleAttr = $style !== '' ? ' style="'.$this->escape($style).'"' : '';

        $colgroup = '';
        if ($t->gridColumnsTwips !== null && $t->gridColumnsTwips !== []) {
            $colgroup = '<colgroup>';
            foreach ($t->gridColumnsTwips as $w) {
                $colgroup .= '<col style="width:'.$this->twipsToPt($w).'pt"/>';
            }
            $colgroup .= '</colgroup>';
        }

        $caption = $t->caption !== null && $t->caption !== ''
            ? '<caption>'.$this->escape($t->caption).'</caption>'
            : '';

        $rows = '';
        foreach ($t->rows as $row) {
            $rows .= $this->renderTableRow($row);
        }

        return '<table'.$styleAttr.'>'.$caption.$colgroup.$rows.'</table>';
    }

    private function renderTableRow(TableRow $row): string
    {
        $cells = '';
        foreach ($row->cells as $cell) {
            // The continue cells (vMergeContinue) are dropped in the HTML —
            // they are merged into the rowspan of the main cell.
            if ($cell->style->vMergeContinue) {
                continue;
            }
            $cells .= $this->renderTableCell($cell, isHeader: $row->isHeader);
        }

        return '<tr>'.$cells.'</tr>';
    }

    private function renderTableCell(TableCell $cell, bool $isHeader): string
    {
        $tag = $isHeader ? 'th' : 'td';
        $attrs = '';
        if ($cell->style->gridSpan > 1) {
            $attrs .= ' colspan="'.$cell->style->gridSpan.'"';
        }
        if ($cell->style->rowSpan > 1) {
            $attrs .= ' rowspan="'.$cell->style->rowSpan.'"';
        }
        $style = $this->cellStyleCss($cell->style);
        if ($style !== '') {
            $attrs .= ' style="'.$this->escape($style).'"';
        }
        $content = $this->renderBlocks($cell->children);

        return '<'.$tag.$attrs.'>'.$content.'</'.$tag.'>';
    }

    private function paragraphStyleCss(ParagraphStyle $s): string
    {
        $parts = [];
        if ($s->alignment !== Alignment::Start) {
            $parts[] = 'text-align:'.match ($s->alignment) {
                Alignment::Center => 'center',
                Alignment::End => 'right',
                Alignment::Justify => 'justify',
                Alignment::Distribute => 'justify',
                default => 'left',
            };
        }
        if ($s->indentLeftTwips !== 0) {
            $parts[] = 'margin-left:'.$this->twipsToPt($s->indentLeftTwips).'pt';
        }
        if ($s->indentRightTwips !== 0) {
            $parts[] = 'margin-right:'.$this->twipsToPt($s->indentRightTwips).'pt';
        }
        if ($s->indentFirstLineTwips !== 0) {
            $parts[] = 'text-indent:'.$this->twipsToPt($s->indentFirstLineTwips).'pt';
        }
        if ($s->lineSpacingTwips !== null && $s->lineSpacingTwips > 0) {
            $rule = $s->lineSpacingRule ?? 'auto';

            if ($rule !== 'auto') {
                // `exact` and `atLeast` set the line height directly — we carry
                // them over as they are.
                $parts[] = 'line-height:'.$this->twipsToPt($s->lineSpacingTwips).'pt';
            } elseif ($s->lineSpacingTwips !== self::SINGLE_LINE_TWIPS) {
                // `auto` is a fraction of the SINGLE spacing, and a single
                // spacing in Word is the font's natural line height (about 1.2
                // of the font size) rather than the font size itself. A CSS
                // multiplier is counted from the font size, so carrying 240 over
                // directly as 1.0 squeezed the lines against the original: on
                // the reference document that immediately drove the last page
                // almost empty.
                //
                // A single spacing is not carried over at all: the engine has
                // its own, and it is closer to the typographic norm than any
                // approximation of ours.
                $parts[] = 'line-height:'.round(
                    $s->lineSpacingTwips / self::SINGLE_LINE_TWIPS * self::SINGLE_LINE_EM,
                    3,
                );
            }
        }
        if ($s->spaceBeforeTwips !== 0) {
            $parts[] = 'margin-top:'.$this->twipsToPt($s->spaceBeforeTwips).'pt';
        }
        if ($s->spaceAfterTwips !== 0) {
            $parts[] = 'margin-bottom:'.$this->twipsToPt($s->spaceAfterTwips).'pt';
        }
        if ($s->shadingColor !== null) {
            $parts[] = 'background-color:#'.$s->shadingColor;
        }
        if ($s->keepWithNext) {
            // The standard CSS property: "do not break after this block".
            $parts[] = 'break-after:avoid';
        }
        if ($s->borders !== null) {
            foreach ($this->borderSetCss($s->borders) as $cssLine) {
                $parts[] = $cssLine;
            }
        }

        return implode(';', $parts);
    }

    private function tableStyleCss(TableStyle $s): string
    {
        $parts = ['border-collapse:collapse'];
        if ($s->widthTwips !== null) {
            $parts[] = 'width:'.$this->twipsToPt($s->widthTwips).'pt';
        } elseif ($s->widthPercent !== null) {
            $parts[] = 'width:'.($s->widthPercent / 50).'%';
        }
        if ($s->alignment === Alignment::Center) {
            $parts[] = 'margin-left:auto';
            $parts[] = 'margin-right:auto';
        }
        if ($s->borders !== null) {
            foreach ($this->borderSetCss($s->borders) as $cssLine) {
                $parts[] = $cssLine;
            }
        }

        return implode(';', $parts);
    }

    private function cellStyleCss(CellStyle $s): string
    {
        $parts = [];
        if ($s->widthTwips !== null) {
            $parts[] = 'width:'.$this->twipsToPt($s->widthTwips).'pt';
        } elseif ($s->widthPercent !== null) {
            $parts[] = 'width:'.($s->widthPercent / 50).'%';
        }
        if ($s->backgroundColor !== null) {
            $parts[] = 'background-color:#'.$s->backgroundColor;
        }
        if ($s->verticalAlign !== VerticalAlign::Top) {
            $parts[] = 'vertical-align:'.match ($s->verticalAlign) {
                VerticalAlign::Center => 'middle',
                VerticalAlign::Bottom => 'bottom',
                default => 'top',
            };
        }
        if ($s->paddingTopTwips !== 0
            || $s->paddingRightTwips !== 0
            || $s->paddingBottomTwips !== 0
            || $s->paddingLeftTwips !== 0) {
            $parts[] = sprintf(
                'padding:%fpt %fpt %fpt %fpt',
                $this->twipsToPt($s->paddingTopTwips),
                $this->twipsToPt($s->paddingRightTwips),
                $this->twipsToPt($s->paddingBottomTwips),
                $this->twipsToPt($s->paddingLeftTwips),
            );
        }
        if ($s->borders !== null) {
            foreach ($this->borderSetCss($s->borders) as $cssLine) {
                $parts[] = $cssLine;
            }
        }

        return implode(';', $parts);
    }

    /**
     * @return list<string>
     */
    private function borderSetCss(BorderSet $set): array
    {
        $out = [];
        foreach (['top' => $set->top, 'left' => $set->left, 'bottom' => $set->bottom, 'right' => $set->right] as $side => $b) {
            if ($b === null) {
                continue;
            }
            $css = $this->borderCss($b);
            if ($css !== null) {
                $out[] = 'border-'.$side.':'.$css;
            }
        }

        return $out;
    }

    private function borderCss(Border $b): ?string
    {
        if ($b->style === BorderStyle::None) {
            return 'none';
        }
        $style = match ($b->style) {
            BorderStyle::Dashed => 'dashed',
            BorderStyle::Dotted => 'dotted',
            BorderStyle::Double => 'double',
            default => 'solid',
        };
        // The size in OOXML is in eighths of a point. In CSS it is pt.
        $widthPt = max(0.1, $b->sizeEighthsOfPoint / 8);

        return sprintf('%.2fpt %s #%s', $widthPt, $style, $b->color);
    }

    private function twipsToPt(int $twips): float
    {
        // 1 twip = 1/20 pt
        return round($twips / 20, 2);
    }

    private function halfPtToPt(int $halfPoints): float
    {
        return round($halfPoints / 2, 2);
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
    }
}
