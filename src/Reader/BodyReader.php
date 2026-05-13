<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Reader;

use Dskripchenko\PhpDocx\Element\BlockElement;
use Dskripchenko\PhpDocx\Element\InlineElement;
use Dskripchenko\PhpDocx\Element\LineBreak;
use Dskripchenko\PhpDocx\Element\PageBreak;
use Dskripchenko\PhpDocx\Element\Paragraph;
use Dskripchenko\PhpDocx\Element\Run;
use Dskripchenko\PhpDocx\Style\ParagraphStyle;
use Dskripchenko\PhpDocx\Style\RunStyle;

/**
 * Phase 3 — body XML → list<BlockElement>.
 *
 * Walks `<w:body>` (или `<w:hdr>`/`<w:ftr>` — та же структура), маппит
 * `<w:p>` → Paragraph, `<w:tbl>` → null (Phase 4 заполнит), `<w:sectPr>`
 * → skip (это metadata).
 *
 * Run-обход внутри `<w:p>`:
 *  - `<w:r>` → Run (с effective styles от StylesResolver) или LineBreak/PageBreak
 *  - `<w:t>` → текст
 *  - `<w:br w:type="page">` → PageBreak (split: всё что после идёт в новый параграф)
 *  - `<w:br/>` → LineBreak
 *  - `<w:tab/>` → "\t" в text
 *  - `<w:hyperlink>` — пока проксируем как inline children (Phase 7 заменит на Hyperlink)
 *  - `<w:fldSimple>`/`<w:fldChar>` — пока inline-текст из contentRuns (Phase 7 заменит на Field)
 *  - `<w:bookmarkStart>`/`<w:bookmarkEnd>` — пока skip (Phase 7 заменит на Bookmark)
 *  - `<w:drawing>` — Phase 6 заполнит
 *
 * Hooks для следующих phase'ов: TableReader, HyperlinkReader, ImageReader,
 * FieldReader подключаются через сеттеры/конструктор позже.
 */
final class BodyReader
{
    private ?TableReader $tableReader = null;

    public function __construct(
        private readonly StylesResolver $styles = new StylesResolver,
    ) {}

    /**
     * Lazy-инициализация TableReader (циклическая зависимость с BodyReader,
     * поэтому делаем lazy через self-reference).
     */
    private function tableReader(): TableReader
    {
        return $this->tableReader ??= new TableReader($this, $this->styles->parser());
    }

    /**
     * @return list<BlockElement>
     */
    public function read(\DOMElement $body): array
    {
        $blocks = [];
        foreach ($body->childNodes as $child) {
            if (! $child instanceof \DOMElement || $child->namespaceURI !== OoxmlNs::W) {
                continue;
            }
            switch ($child->localName) {
                case 'p':
                    foreach ($this->readParagraph($child) as $b) {
                        $blocks[] = $b;
                    }
                    break;
                case 'tbl':
                    $blocks[] = $this->tableReader()->read($child);
                    break;
                case 'sectPr':
                    // metadata, не block
                    break;
                default:
                    break;
            }
        }

        return $blocks;
    }

    /**
     * Парсит `<w:p>` в один или несколько Block'ов (PageBreak может split'нуть).
     *
     * @return list<BlockElement>
     */
    public function readParagraph(\DOMElement $p): array
    {
        [$pStyle, $runBase, $headingLevel] = $this->styles->effectiveStylesForParagraph($p);
        $inlines = $this->readInlines($p, $runBase);

        // Если в inlines обнаружен PageBreak — разделяем на 2 параграфа
        // ("text-before [PageBreak] text-after").
        return $this->splitOnPageBreak($inlines, $pStyle, $headingLevel);
    }

    /**
     * Обход inline children внутри `<w:p>` (или внутри hyperlink/etc.).
     *
     * @return list<InlineElement>
     */
    public function readInlines(\DOMElement $parent, RunStyle $baseRunStyle): array
    {
        $out = [];
        foreach ($parent->childNodes as $child) {
            if (! $child instanceof \DOMElement || $child->namespaceURI !== OoxmlNs::W) {
                continue;
            }
            $local = $child->localName;
            if ($local === 'pPr') {
                continue;
            }
            if ($local === 'r') {
                foreach ($this->readRun($child, $baseRunStyle) as $i) {
                    $out[] = $i;
                }

                continue;
            }
            if ($local === 'hyperlink') {
                // Phase 7 будет вставлять Hyperlink — пока flatten children
                foreach ($this->readInlines($child, $baseRunStyle) as $i) {
                    $out[] = $i;
                }

                continue;
            }
            if ($local === 'fldSimple') {
                // Простой field — emit content runs как текст (Phase 7 заменит).
                foreach ($this->readInlines($child, $baseRunStyle) as $i) {
                    $out[] = $i;
                }

                continue;
            }
            if ($local === 'bookmarkStart' || $local === 'bookmarkEnd') {
                // Phase 7 emit Bookmark; пока skip.
                continue;
            }
            // unknown — skip
        }

        return $out;
    }

    /**
     * `<w:r>` → 1+ inline (Run/LineBreak/PageBreak).
     *
     * @return list<InlineElement>
     */
    private function readRun(\DOMElement $r, RunStyle $baseRunStyle): array
    {
        $runStyle = $this->styles->effectiveStylesForRun($r, $baseRunStyle);
        $out = [];
        $textBuffer = '';

        $flushText = function () use (&$out, &$textBuffer, $runStyle): void {
            if ($textBuffer !== '') {
                $out[] = new Run($textBuffer, $runStyle);
                $textBuffer = '';
            }
        };

        foreach ($r->childNodes as $child) {
            if (! $child instanceof \DOMElement || $child->namespaceURI !== OoxmlNs::W) {
                continue;
            }
            switch ($child->localName) {
                case 't':
                    $textBuffer .= $child->textContent;
                    break;
                case 'tab':
                    $textBuffer .= "\t";
                    break;
                case 'noBreakHyphen':
                    $textBuffer .= "\u{2011}"; // non-breaking hyphen
                    break;
                case 'softHyphen':
                    $textBuffer .= "\u{00AD}";
                    break;
                case 'br':
                    $flushText();
                    $type = $child->getAttributeNS(OoxmlNs::W, 'type');
                    $out[] = $type === 'page' ? new PageBreak : new LineBreak;
                    break;
                case 'rPr':
                    // already used by effectiveStylesForRun
                    break;
                default:
                    // sym, instrText, drawing — обработка в next phases
                    break;
            }
        }
        $flushText();

        return $out;
    }

    /**
     * Разделяет inline-список на параграфы по PageBreak'ам.
     *
     * @param  list<InlineElement>  $inlines
     * @return list<BlockElement>
     */
    private function splitOnPageBreak(array $inlines, ParagraphStyle $style, ?int $headingLevel): array
    {
        $blocks = [];
        $current = [];
        foreach ($inlines as $i) {
            if ($i instanceof PageBreak) {
                if ($current !== []) {
                    $blocks[] = new Paragraph($current, $style, $headingLevel);
                    $current = [];
                }
                $blocks[] = $i;

                continue;
            }
            $current[] = $i;
        }
        if ($current !== [] || $blocks === []) {
            $blocks[] = new Paragraph($current, $style, $headingLevel);
        }

        return $blocks;
    }
}
