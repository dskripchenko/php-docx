<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Reader;

use Dskripchenko\PhpDocx\Element\BlockElement;
use Dskripchenko\PhpDocx\Element\Image;
use Dskripchenko\PhpDocx\Element\InlineElement;
use Dskripchenko\PhpDocx\Element\LineBreak;
use Dskripchenko\PhpDocx\Element\ListItem;
use Dskripchenko\PhpDocx\Element\ListNode;
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
        private readonly NumberingDefinitions $numbering = new NumberingDefinitions,
        private readonly ?ImageReader $imageReader = null,
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
        /** @var list<array{numId:int, ilvl:int, inlines:list<InlineElement>}> $pendingList */
        $pendingList = [];
        $pendingNumId = null;

        $flushList = function () use (&$pendingList, &$pendingNumId, &$blocks): void {
            if ($pendingList === []) {
                return;
            }
            $blocks[] = $this->buildListNode($pendingList, $pendingNumId);
            $pendingList = [];
            $pendingNumId = null;
        };

        foreach ($body->childNodes as $child) {
            if (! $child instanceof \DOMElement || $child->namespaceURI !== OoxmlNs::W) {
                continue;
            }
            switch ($child->localName) {
                case 'p':
                    $item = $this->classifyParagraph($child);
                    if ($item !== null) {
                        // List paragraph.
                        if ($pendingNumId !== null && $pendingNumId !== $item['numId']) {
                            $flushList();
                        }
                        $pendingNumId = $item['numId'];
                        $pendingList[] = $item;
                        break;
                    }
                    // Обычный параграф.
                    $flushList();
                    foreach ($this->readParagraph($child) as $b) {
                        $blocks[] = $b;
                    }
                    break;
                case 'tbl':
                    $flushList();
                    $blocks[] = $this->tableReader()->read($child);
                    break;
                case 'sectPr':
                    break;
                default:
                    break;
            }
        }
        $flushList();

        return $blocks;
    }

    /**
     * Если параграф — list-item (numPr+зарегистрированный numId), возвращает
     * descriptor. Иначе null (обычный параграф).
     *
     * @return array{numId:int, ilvl:int, inlines:list<InlineElement>}|null
     */
    private function classifyParagraph(\DOMElement $p): ?array
    {
        [, $runBase, , $numId, $ilvl] = $this->styles->effectiveStylesForParagraph($p);
        if ($numId === null || ! $this->numbering->hasNumId($numId)) {
            return null;
        }
        $inlines = $this->readInlines($p, $runBase);
        // Убираем internal PageBreaks (Word редко кладёт их в list-items;
        // если они есть — flatten как обычный inline).
        $cleanInlines = array_values(array_filter(
            $inlines,
            fn ($i) => ! $i instanceof PageBreak,
        ));

        return [
            'numId' => $numId,
            'ilvl' => max(0, $ilvl ?? 0),
            'inlines' => $cleanInlines,
        ];
    }

    /**
     * Из flat-списка list-items (по возрастанию или ad-hoc ilvl) собирает
     * дерево ListNode/ListItem с nesting'ом.
     *
     * @param  list<array{numId:int, ilvl:int, inlines:list<InlineElement>}>  $items
     */
    private function buildListNode(array $items, ?int $numId): ListNode
    {
        if ($numId === null) {
            // Не должно случаться — но fallback.
            return new ListNode([]);
        }
        $minIlvl = $this->minIlvl($items);
        $root = $this->buildRecursive($items, 0, count($items), $minIlvl, $numId);
        if ($root instanceof ListNode) {
            return $root;
        }

        return new ListNode([]);
    }

    /**
     * @param  list<array{numId:int, ilvl:int, inlines:list<InlineElement>}>  $items
     */
    private function minIlvl(array $items): int
    {
        $min = PHP_INT_MAX;
        foreach ($items as $it) {
            if ($it['ilvl'] < $min) {
                $min = $it['ilvl'];
            }
        }

        return $min === PHP_INT_MAX ? 0 : $min;
    }

    /**
     * Рекурсивно строит ListNode для items[from..to] на заданном depth.
     * Items с ilvl == depth → siblings в текущем ListNode; items с
     * ilvl > depth → nested внутрь предыдущего siblng'а.
     *
     * @param  list<array{numId:int, ilvl:int, inlines:list<InlineElement>}>  $items
     */
    private function buildRecursive(array $items, int $from, int $to, int $depth, int $numId): ListNode
    {
        $siblings = [];
        $i = $from;
        while ($i < $to) {
            $cur = $items[$i];
            if ($cur['ilvl'] < $depth) {
                break; // отдадим обратно вверх
            }
            if ($cur['ilvl'] > $depth) {
                // children предыдущего sibling'а
                $j = $i;
                while ($j < $to && $items[$j]['ilvl'] > $depth) {
                    $j++;
                }
                $nested = $this->buildRecursive($items, $i, $j, $depth + 1, $numId);
                // Прикрепить как nestedList последнего sibling'а, если есть.
                if ($siblings !== []) {
                    $last = $siblings[count($siblings) - 1];
                    $siblings[count($siblings) - 1] = new ListItem($last->children, $nested);
                } else {
                    // Нет предыдущего sibling'а — оборачиваем в пустой parent-item.
                    $siblings[] = new ListItem([], $nested);
                }
                $i = $j;

                continue;
            }
            // ilvl == depth — обычный sibling.
            $siblings[] = new ListItem($cur['inlines']);
            $i++;
        }

        $format = $this->numbering->formatFor($numId, $depth);
        $startAt = $this->numbering->startAtFor($numId, $depth);
        $ordered = $this->numbering->isOrdered($numId, $depth);

        return new ListNode(
            items: $siblings,
            ordered: $ordered,
            levelStart: 0, // дочерние уровни сами знают свой ilvl через nesting
            format: $format,
            startAt: $startAt,
        );
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
                case 'drawing':
                    $flushText();
                    if ($this->imageReader !== null) {
                        $image = $this->imageReader->read($child);
                        if ($image instanceof Image) {
                            $out[] = $image;
                        }
                    }
                    break;
                case 'rPr':
                    // already used by effectiveStylesForRun
                    break;
                default:
                    // sym, instrText — обработка в next phases
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
