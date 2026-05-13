<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx;

use Dskripchenko\PhpDocx\Element\BlockElement;
use Dskripchenko\PhpDocx\Style\PageSetup;

/**
 * Section — логическая секция документа: набор block-элементов + page setup
 * + header/footer.
 *
 * **Header/footer типы.** Word поддерживает три типа: `default` (на всех
 * страницах), `first` (на первой странице), `even` (на чётных). Default
 * хранится в `$header`/`$footer`; `$firstHeader`/`$evenHeader` (и footer
 * аналоги) — опциональные дополнительные blocks.
 *
 * Если `$firstHeader` или `$firstFooter` не пуст, writer эмитит
 * `<w:titlePg/>` в `<w:sectPr>` (требование Word).
 *
 * Если `$evenHeader` или `$evenFooter` не пуст, writer также эмитит
 * `<w:evenAndOddHeaders/>` в `word/settings.xml` (без этого Word
 * игнорирует even-headers).
 *
 * В простом случае весь документ — одна секция. Multi-section для
 * mid-document смены ориентации/полей не покрывается v1.
 */
final readonly class Section
{
    /**
     * @param  list<BlockElement>  $body
     * @param  list<BlockElement>  $header  Default header.
     * @param  list<BlockElement>  $footer  Default footer.
     * @param  list<BlockElement>  $firstHeader  Header только для первой
     *                                           страницы (если непустой —
     *                                           sectPr получит titlePg).
     * @param  list<BlockElement>  $firstFooter  Footer только для первой страницы.
     * @param  list<BlockElement>  $evenHeader  Header для чётных страниц
     *                                          (требует evenAndOddHeaders
     *                                          в settings.xml — writer
     *                                          добавит автоматически).
     * @param  list<BlockElement>  $evenFooter  Footer для чётных страниц.
     */
    public function __construct(
        public array $body,
        public array $header = [],
        public array $footer = [],
        public PageSetup $pageSetup = new PageSetup,
        public array $firstHeader = [],
        public array $firstFooter = [],
        public array $evenHeader = [],
        public array $evenFooter = [],
    ) {}

    /**
     * Все non-empty headers по типу (default/first/even).
     *
     * @return array<string, list<BlockElement>>
     */
    public function allHeaders(): array
    {
        $out = [];
        if ($this->header !== []) {
            $out['default'] = $this->header;
        }
        if ($this->firstHeader !== []) {
            $out['first'] = $this->firstHeader;
        }
        if ($this->evenHeader !== []) {
            $out['even'] = $this->evenHeader;
        }

        return $out;
    }

    /**
     * Все non-empty footers по типу.
     *
     * @return array<string, list<BlockElement>>
     */
    public function allFooters(): array
    {
        $out = [];
        if ($this->footer !== []) {
            $out['default'] = $this->footer;
        }
        if ($this->firstFooter !== []) {
            $out['first'] = $this->firstFooter;
        }
        if ($this->evenFooter !== []) {
            $out['even'] = $this->evenFooter;
        }

        return $out;
    }

    public function hasFirstPageHeaderOrFooter(): bool
    {
        return $this->firstHeader !== [] || $this->firstFooter !== [];
    }

    public function hasEvenPageHeaderOrFooter(): bool
    {
        return $this->evenHeader !== [] || $this->evenFooter !== [];
    }
}
