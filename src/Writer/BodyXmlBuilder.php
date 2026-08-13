<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Writer;

use Dskripchenko\PhpDocx\Element\BlockElement;
use Dskripchenko\PhpDocx\Element\Bookmark;
use Dskripchenko\PhpDocx\Element\Field;
use Dskripchenko\PhpDocx\Element\HorizontalRule;
use Dskripchenko\PhpDocx\Element\Hyperlink;
use Dskripchenko\PhpDocx\Element\Image;
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
 * Renders an element tree into the OOXML body XML (without the <w:body>
 * wrapper).
 *
 * Every method is pure: it takes an element plus a RelationshipManager (to
 * register the image and hyperlink relationships) and returns an XML fragment as
 * a string.
 *
 * The style serialization lives in
 * `renderRunStyle()`/`renderParagraphStyle()` and the like. Skip-empty: when a
 * style is empty no `<w:rPr/>` is emitted (Word accepts both).
 */
final class BodyXmlBuilder
{
    private bool $usesNumbering = false;

    /** The counter of the bookmarks' w:id (unique within a document). */
    private int $bookmarkIdCounter = 0;

    public function __construct(
        private readonly RelationshipManager $rels = new RelationshipManager,
        private readonly NumberingXmlBuilder $numbering = new NumberingXmlBuilder,
    ) {}

    public function usesNumbering(): bool
    {
        return $this->usesNumbering;
    }

    public function numbering(): NumberingXmlBuilder
    {
        return $this->numbering;
    }

    /**
     * @param  list<BlockElement>  $blocks
     */
    public function render(array $blocks): string
    {
        $xml = '';
        foreach ($blocks as $b) {
            $xml .= $this->renderBlock($b);
        }

        return $xml;
    }

    public function relationships(): RelationshipManager
    {
        return $this->rels;
    }

    private function renderBlock(BlockElement $block): string
    {
        return match (true) {
            $block instanceof Paragraph => $this->renderParagraph($block),
            $block instanceof Table => $this->renderTable($block),
            $block instanceof PageBreak => $this->renderPageBreak(),
            $block instanceof HorizontalRule => $this->renderHr(),
            $block instanceof Image => $this->renderBlockImage($block),
            $block instanceof ListNode => $this->renderListNode($block),
            default => '',
        };
    }

    // ──────────────────────────── Lists ──────────────────────────────────────

    private function renderListNode(ListNode $list): string
    {
        $numId = $this->numbering->instanceFor($list->effectiveFormat(), $list->startAt);
        $this->usesNumbering = true;

        $xml = '';
        foreach ($list->items as $item) {
            $xml .= $this->renderListItem($item, $numId, $list->levelStart);
        }

        return $xml;
    }

    private function renderListItem(ListItem $item, int $numId, int $level): string
    {
        $level = max(0, min(NumberingXmlBuilder::MAX_LEVELS - 1, $level));
        $pPr = '<w:pPr><w:pStyle w:val="ListParagraph"/>'
            .'<w:numPr><w:ilvl w:val="'.$level.'"/><w:numId w:val="'.$numId.'"/></w:numPr>'
            .'</w:pPr>';

        $children = '';
        foreach ($item->children as $child) {
            $children .= $this->renderInline($child);
        }
        $xml = '<w:p>'.$pPr.$children.'</w:p>';

        // A nested list — recursively, but with the level incremented.
        if ($item->nestedList !== null) {
            $nestedNumId = $this->numbering->instanceFor(
                $item->nestedList->effectiveFormat(),
                $item->nestedList->startAt,
            );
            $this->usesNumbering = true;
            foreach ($item->nestedList->items as $sub) {
                $xml .= $this->renderListItem($sub, $nestedNumId, $level + 1);
            }
        }

        return $xml;
    }

    // ──────────────────────────── Paragraph + Run ────────────────────────────

    private function renderParagraph(Paragraph $p): string
    {
        $pPr = $this->renderParagraphProperties($p->style, $p->headingLevel);
        $children = '';
        foreach ($p->children as $child) {
            $children .= $this->renderInline($child);
        }

        return '<w:p>'.$pPr.$children.'</w:p>';
    }

    private function renderParagraphProperties(ParagraphStyle $style, ?int $headingLevel): string
    {
        $pPr = '';

        if ($headingLevel !== null && $headingLevel >= 1 && $headingLevel <= 6) {
            $pPr .= '<w:pStyle w:val="Heading'.$headingLevel.'"/>';
        }
        if ($style->borders !== null) {
            $pPr .= $this->renderBorderSet($style->borders, 'w:pBdr');
        }
        // The order of the elements inside a w:pPr is fixed by the schema
        // (CT_PPrBase is a sequence, unlike w:rPr): shd sits between pBdr and
        // spacing.
        if ($style->shadingColor !== null) {
            $pPr .= '<w:shd w:val="clear" w:color="auto" w:fill="'.$style->shadingColor.'"/>';
        }
        if ($style->spaceBeforeTwips !== 0 || $style->spaceAfterTwips !== 0 || $style->lineSpacingTwips !== null) {
            $attrs = [];
            if ($style->spaceBeforeTwips !== 0) {
                $attrs[] = 'w:before="'.$style->spaceBeforeTwips.'"';
            }
            if ($style->spaceAfterTwips !== 0) {
                $attrs[] = 'w:after="'.$style->spaceAfterTwips.'"';
            }
            if ($style->lineSpacingTwips !== null) {
                $attrs[] = 'w:line="'.$style->lineSpacingTwips.'"';
                $attrs[] = 'w:lineRule="exact"';
            }
            $pPr .= '<w:spacing '.implode(' ', $attrs).'/>';
        }
        if ($style->indentLeftTwips !== 0 || $style->indentRightTwips !== 0 || $style->indentFirstLineTwips !== 0) {
            $attrs = [];
            if ($style->indentLeftTwips !== 0) {
                $attrs[] = 'w:left="'.$style->indentLeftTwips.'"';
            }
            if ($style->indentRightTwips !== 0) {
                $attrs[] = 'w:right="'.$style->indentRightTwips.'"';
            }
            if ($style->indentFirstLineTwips > 0) {
                $attrs[] = 'w:firstLine="'.$style->indentFirstLineTwips.'"';
            } elseif ($style->indentFirstLineTwips < 0) {
                // ST_TwipsMeasure is unsigned: a negative firstLine (our model
                // of a hanging indent, see OoxmlPropertyParser) is written as
                // the w:hanging attribute with the absolute value.
                $attrs[] = 'w:hanging="'.(-$style->indentFirstLineTwips).'"';
            }
            $pPr .= '<w:ind '.implode(' ', $attrs).'/>';
        }
        if ($style->alignment !== Alignment::Start) {
            $pPr .= '<w:jc w:val="'.$style->alignment->value.'"/>';
        }

        if ($pPr === '') {
            return '';
        }

        return '<w:pPr>'.$pPr.'</w:pPr>';
    }

    private function renderInline(InlineElement $el): string
    {
        return match (true) {
            $el instanceof Run => $this->renderRun($el),
            $el instanceof LineBreak => '<w:r><w:br/></w:r>',
            $el instanceof Hyperlink => $this->renderHyperlink($el),
            $el instanceof Image => $this->renderInlineImage($el),
            $el instanceof Field => $this->renderField($el),
            $el instanceof Bookmark => $this->renderBookmark($el),
            default => '',
        };
    }

    /**
     * `<w:bookmarkStart>` + content + `<w:bookmarkEnd>`. ID auto-allocated.
     */
    private function renderBookmark(Bookmark $b): string
    {
        $id = ++$this->bookmarkIdCounter;
        $name = XmlEscape::attr($b->name);
        $children = '';
        foreach ($b->children as $child) {
            $children .= $this->renderInline($child);
        }

        return '<w:bookmarkStart w:id="'.$id.'" w:name="'.$name.'"/>'
            .$children
            .'<w:bookmarkEnd w:id="'.$id.'"/>';
    }

    /**
     * A `<w:fldSimple>` for the field codes
     * (PAGE/NUMPAGES/DATE/TIME/AUTHOR/TITLE). Word and Pages recompute the value
     * when the file is opened.
     */
    private function renderField(Field $f): string
    {
        $instr = XmlEscape::attr(' '.$f->instruction.' ');
        $rPr = $this->renderRunProperties($f->style);
        // The placeholder text — it is replaced by the real value at render time.
        $placeholder = '<w:r>'.$rPr.'<w:t>0</w:t></w:r>';

        return '<w:fldSimple w:instr="'.$instr.'">'.$placeholder.'</w:fldSimple>';
    }

    private function renderRun(Run $r): string
    {
        $rPr = $this->renderRunProperties($r->style);
        $text = XmlEscape::text($r->text);
        // xml:space="preserve" keeps the leading and trailing spaces.
        $textNode = '<w:t xml:space="preserve">'.$text.'</w:t>';

        return '<w:r>'.$rPr.$textNode.'</w:r>';
    }

    private function renderRunProperties(RunStyle $s): string
    {
        if ($s->isEmpty()) {
            return '';
        }
        $rPr = '';
        if ($s->fontFamily !== null) {
            $f = XmlEscape::attr($s->fontFamily);
            $rPr .= '<w:rFonts w:ascii="'.$f.'" w:hAnsi="'.$f.'" w:cs="'.$f.'"/>';
        }
        if ($s->bold) {
            $rPr .= '<w:b/><w:bCs/>';
        }
        if ($s->italic) {
            $rPr .= '<w:i/><w:iCs/>';
        }
        if ($s->underline) {
            $rPr .= '<w:u w:val="single"/>';
        }
        if ($s->strikethrough) {
            $rPr .= '<w:strike/>';
        }
        if ($s->superscript) {
            $rPr .= '<w:vertAlign w:val="superscript"/>';
        }
        if ($s->subscript) {
            $rPr .= '<w:vertAlign w:val="subscript"/>';
        }
        if ($s->color !== null) {
            $rPr .= '<w:color w:val="'.$s->color.'"/>';
        }
        if ($s->letterSpacingTwips !== null) {
            // Character spacing. It is a namesake of the element in w:pPr, but a
            // different property: that one is about the spacing between lines,
            // this one about the spacing between letters.
            $rPr .= '<w:spacing w:val="'.$s->letterSpacingTwips.'"/>';
        }
        if ($s->sizeHalfPoints !== null) {
            $rPr .= '<w:sz w:val="'.$s->sizeHalfPoints.'"/><w:szCs w:val="'.$s->sizeHalfPoints.'"/>';
        }
        // A background colour at the run level is interpreted in OOXML as a
        // horizontal stripe (in Pages) or a highlight (in Word) — both usually
        // look unexpected. When a caller wants a background for the text, a
        // cell-level shd or a w:highlight is better. We ignore
        // RunStyle.backgroundColor.

        if ($s->highlight !== null) {
            $rPr .= '<w:highlight w:val="'.$s->highlight.'"/>';
        }

        return '<w:rPr>'.$rPr.'</w:rPr>';
    }

    private function renderHyperlink(Hyperlink $h): string
    {
        $children = '';
        foreach ($h->children as $child) {
            $children .= $this->renderInline($child);
        }

        if ($h->isInternal()) {
            // An internal link to a bookmark — no rId, only a w:anchor.
            $anchor = XmlEscape::attr((string) $h->anchor);

            return '<w:hyperlink w:anchor="'.$anchor.'">'.$children.'</w:hyperlink>';
        }

        $rId = $this->rels->registerHyperlink((string) $h->href);

        return '<w:hyperlink r:id="'.$rId.'">'.$children.'</w:hyperlink>';
    }

    // ──────────────────────────── PageBreak / HR ─────────────────────────────

    private function renderPageBreak(): string
    {
        return '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
    }

    private function renderHr(): string
    {
        return '<w:p>'
            .'<w:pPr><w:pBdr>'
            .'<w:bottom w:val="single" w:sz="6" w:space="1" w:color="999999"/>'
            .'</w:pBdr></w:pPr>'
            .'</w:p>';
    }

    // ──────────────────────────── Table ──────────────────────────────────────

    private function renderTable(Table $t): string
    {
        $caption = $t->caption !== null && $t->caption !== ''
            ? $this->renderCaption($t->caption)
            : '';

        $tblPr = $this->renderTableProperties($t->style);
        $gridCols = $t->gridColumnsTwips ?? $this->computeGridColumns($t);
        $gridXml = '<w:tblGrid>';
        foreach ($gridCols as $widthTwips) {
            $gridXml .= '<w:gridCol w:w="'.$widthTwips.'"/>';
        }
        $gridXml .= '</w:tblGrid>';

        $rows = '';
        foreach ($t->rows as $row) {
            $rows .= $this->renderTableRow($row);
        }

        return $caption.'<w:tbl>'.$tblPr.$gridXml.$rows.'</w:tbl>';
    }

    /**
     * The caption paragraph before a table. The "Caption" style is registered
     * in StyleRegistry::defaults() (italic, centred, 10pt, grey).
     */
    private function renderCaption(string $text): string
    {
        $escaped = XmlEscape::text($text);

        return '<w:p>'
            .'<w:pPr><w:pStyle w:val="Caption"/><w:jc w:val="center"/></w:pPr>'
            .'<w:r><w:rPr><w:i/><w:iCs/><w:color w:val="6b7280"/><w:sz w:val="20"/></w:rPr>'
            .'<w:t xml:space="preserve">'.$escaped.'</w:t></w:r>'
            .'</w:p>';
    }

    private function renderTableProperties(TableStyle $s): string
    {
        // The order of the elements in a <w:tblPr> per the OOXML CT_TblPr
        // schema: tblW → jc → tblBorders → tblLayout → tblCellMar. Word and
        // Pages ignore the properties when the order is broken.
        $tblPr = '';

        // width
        if ($s->widthTwips !== null) {
            $tblPr .= '<w:tblW w:w="'.$s->widthTwips.'" w:type="dxa"/>';
        } elseif ($s->widthPercent !== null) {
            $tblPr .= '<w:tblW w:w="'.$s->widthPercent.'" w:type="pct"/>';
        } else {
            $tblPr .= '<w:tblW w:w="0" w:type="auto"/>';
        }

        // alignment
        if ($s->alignment !== Alignment::Start) {
            $tblPr .= '<w:jc w:val="'.$s->alignment->value.'"/>';
        }

        // borders
        if ($s->borders !== null) {
            $tblPr .= $this->renderBorderSet($s->borders, 'w:tblBorders');
        }

        // layout
        if ($s->fixedLayout) {
            $tblPr .= '<w:tblLayout w:type="fixed"/>';
        }

        // cell margins
        if ($s->cellMarginTopTwips !== 0 || $s->cellMarginRightTwips !== 0
            || $s->cellMarginBottomTwips !== 0 || $s->cellMarginLeftTwips !== 0) {
            $tblPr .= '<w:tblCellMar>';
            $tblPr .= '<w:top w:w="'.$s->cellMarginTopTwips.'" w:type="dxa"/>';
            $tblPr .= '<w:left w:w="'.$s->cellMarginLeftTwips.'" w:type="dxa"/>';
            $tblPr .= '<w:bottom w:w="'.$s->cellMarginBottomTwips.'" w:type="dxa"/>';
            $tblPr .= '<w:right w:w="'.$s->cellMarginRightTwips.'" w:type="dxa"/>';
            $tblPr .= '</w:tblCellMar>';
        }

        return '<w:tblPr>'.$tblPr.'</w:tblPr>';
    }

    /**
     * Computes the gridCols from the cells of the first row. When the cells
     * have no widthTwips we distribute them equally (5000 twips per column by
     * default).
     *
     * @return list<int>
     */
    private function computeGridColumns(Table $t): array
    {
        if ($t->rows === []) {
            return [];
        }
        // We take the first row and, for every cell, its widthTwips or 0.
        $firstRow = $t->rows[0];
        $cols = [];
        foreach ($firstRow->cells as $cell) {
            $w = $cell->style->widthTwips;
            if ($w === null && $cell->style->widthPercent !== null) {
                // A pct is invalid in the grid — a gridCol requires dxa. The
                // approximation: a percentage of 9000 (roughly an A4 page minus
                // the margins).
                $pct = $cell->style->widthPercent / 50;
                $w = (int) round($pct / 100 * 9000);
            }
            $cols[] = $w ?? 2000;
            // Accounting for the colspan: every "extra" column is added as
            // well (with an equal width).
            for ($i = 1; $i < $cell->style->gridSpan; $i++) {
                $cols[] = $w ?? 2000;
            }
        }

        return $cols;
    }

    private function renderTableRow(TableRow $row): string
    {
        $trPr = '';
        if ($row->isHeader) {
            $trPr .= '<w:tblHeader/>';
        }
        if ($row->heightTwips !== null) {
            $trPr .= '<w:trHeight w:val="'.$row->heightTwips.'" w:hRule="atLeast"/>';
        }
        $trPrXml = $trPr === '' ? '' : '<w:trPr>'.$trPr.'</w:trPr>';

        $cells = '';
        foreach ($row->cells as $cell) {
            $cells .= $this->renderTableCell($cell);
        }

        return '<w:tr>'.$trPrXml.$cells.'</w:tr>';
    }

    private function renderTableCell(TableCell $cell): string
    {
        $tcPr = $this->renderCellProperties($cell->style);

        $content = '';
        foreach ($cell->children as $block) {
            $content .= $this->renderBlock($block);
        }

        // A cell must END with a paragraph. Checking "is there at least one
        // <w:p> inside" is not enough: a nested table has paragraphs of its own
        // inside while the cell still ends on a </w:tbl> — Word opens such a
        // file offering to recover the content.
        if (! str_ends_with($content, '</w:p>') && ! str_ends_with($content, '<w:p/>')) {
            $content .= '<w:p/>';
        }

        return '<w:tc>'.$tcPr.$content.'</w:tc>';
    }

    private function renderCellProperties(CellStyle $s): string
    {
        // The order of the elements in a <w:tcPr> per the OOXML CT_TcPr schema:
        //   tcW → gridSpan → vMerge → tcBorders → shd → tcMar → vAlign
        // When the order is broken, Word and Pages ignore the properties (the
        // background, the padding and the valign are the most visible
        // victims).
        $tcPr = '';

        // width
        if ($s->widthTwips !== null) {
            $tcPr .= '<w:tcW w:w="'.$s->widthTwips.'" w:type="dxa"/>';
        } elseif ($s->widthPercent !== null) {
            $tcPr .= '<w:tcW w:w="'.$s->widthPercent.'" w:type="pct"/>';
        }

        // gridSpan (colspan)
        if ($s->gridSpan > 1) {
            $tcPr .= '<w:gridSpan w:val="'.$s->gridSpan.'"/>';
        }

        // vMerge: the first cell of a merge group carries restart, the
        // continuations carry no val. The converter inserts the continue cells
        // ($vMergeContinue) into the following rows automatically.
        if ($s->vMergeContinue) {
            $tcPr .= '<w:vMerge/>';
        } elseif ($s->rowSpan > 1) {
            $tcPr .= '<w:vMerge w:val="restart"/>';
        }

        // the borders (BEFORE shd)
        if ($s->borders !== null) {
            $tcPr .= $this->renderBorderSet($s->borders, 'w:tcBorders');
        }

        // the background (AFTER the borders, BEFORE tcMar)
        if ($s->backgroundColor !== null) {
            $tcPr .= '<w:shd w:val="clear" w:color="auto" w:fill="'.$s->backgroundColor.'"/>';
        }

        // the padding (AFTER shd)
        if ($s->paddingTopTwips !== 0 || $s->paddingRightTwips !== 0
            || $s->paddingBottomTwips !== 0 || $s->paddingLeftTwips !== 0) {
            $tcPr .= '<w:tcMar>';
            $tcPr .= '<w:top w:w="'.$s->paddingTopTwips.'" w:type="dxa"/>';
            $tcPr .= '<w:left w:w="'.$s->paddingLeftTwips.'" w:type="dxa"/>';
            $tcPr .= '<w:bottom w:w="'.$s->paddingBottomTwips.'" w:type="dxa"/>';
            $tcPr .= '<w:right w:w="'.$s->paddingRightTwips.'" w:type="dxa"/>';
            $tcPr .= '</w:tcMar>';
        }

        // the valign (last)
        if ($s->verticalAlign !== VerticalAlign::Top) {
            $tcPr .= '<w:vAlign w:val="'.$s->verticalAlign->value.'"/>';
        }

        return '<w:tcPr>'.$tcPr.'</w:tcPr>';
    }

    // ──────────────────────────── Borders ────────────────────────────────────

    private function renderBorderSet(BorderSet $set, string $wrapperTag): string
    {
        $sides = [
            'top' => $set->top,
            'left' => $set->left,
            'bottom' => $set->bottom,
            'right' => $set->right,
            'insideH' => $set->insideH,
            'insideV' => $set->insideV,
        ];
        $xml = '';
        foreach ($sides as $key => $b) {
            if ($b === null) {
                continue;
            }
            $xml .= $this->renderBorder($key, $b);
        }
        if ($xml === '') {
            return '';
        }

        return '<'.$wrapperTag.'>'.$xml.'</'.$wrapperTag.'>';
    }

    private function renderBorder(string $side, Border $b): string
    {
        if ($b->style === BorderStyle::None) {
            return '<w:'.$side.' w:val="none" w:sz="0" w:space="0" w:color="auto"/>';
        }

        return '<w:'.$side.' w:val="'.$b->style->value.'" w:sz="'.$b->sizeEighthsOfPoint.'" w:space="0" w:color="'.$b->color.'"/>';
    }

    // ──────────────────────────── Image stubs (Phase 4) ─────────────────────

    private function renderBlockImage(Image $img): string
    {
        // A block-level image: we wrap it into a paragraph of its own.
        return '<w:p>'.$this->renderInlineImage($img).'</w:p>';
    }

    private function renderInlineImage(Image $img): string
    {
        $rId = $this->rels->registerImage($img);
        $cx = $img->widthEmu;
        $cy = $img->heightEmu;
        $alt = XmlEscape::attr($img->altText ?? 'image');
        // A simple inline image through <w:drawing><wp:inline>... The picture's
        // number runs through the whole document: Word declares a file corrupt
        // when two pictures share a number.
        $docPrId = $this->rels->nextDrawingId();

        return <<<XML
            <w:r><w:drawing>
                <wp:inline distT="0" distB="0" distL="0" distR="0">
                    <wp:extent cx="{$cx}" cy="{$cy}"/>
                    <wp:effectExtent l="0" t="0" r="0" b="0"/>
                    <wp:docPr id="{$docPrId}" name="Image" descr="{$alt}"/>
                    <wp:cNvGraphicFramePr><a:graphicFrameLocks xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" noChangeAspect="1"/></wp:cNvGraphicFramePr>
                    <a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">
                        <a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">
                            <pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">
                                <pic:nvPicPr>
                                    <pic:cNvPr id="{$docPrId}" name="Image"/>
                                    <pic:cNvPicPr/>
                                </pic:nvPicPr>
                                <pic:blipFill>
                                    <a:blip r:embed="{$rId}"/>
                                    <a:stretch><a:fillRect/></a:stretch>
                                </pic:blipFill>
                                <pic:spPr>
                                    <a:xfrm><a:off x="0" y="0"/><a:ext cx="{$cx}" cy="{$cy}"/></a:xfrm>
                                    <a:prstGeom prst="rect"><a:avLst/></a:prstGeom>
                                </pic:spPr>
                            </pic:pic>
                        </a:graphicData>
                    </a:graphic>
                </wp:inline>
            </w:drawing></w:r>
            XML;
    }
}
