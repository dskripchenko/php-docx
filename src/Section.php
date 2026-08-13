<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx;

use Dskripchenko\PhpDocx\Element\BlockElement;
use Dskripchenko\PhpDocx\Style\PageSetup;

/**
 * Section is a logical section of the document: a set of block elements plus
 * the page setup and the header/footer.
 *
 * **Header/footer types.** Word supports three: `default` (on every page),
 * `first` (on the first page), `even` (on even ones). The default one is kept
 * in `$header`/`$footer`; `$firstHeader`/`$evenHeader` (and the footer
 * counterparts) are optional extra blocks.
 *
 * When `$firstHeader` or `$firstFooter` is non-empty, the writer emits
 * `<w:titlePg/>` in `<w:sectPr>` (Word requires it).
 *
 * When `$evenHeader` or `$evenFooter` is non-empty, the writer also emits
 * `<w:evenAndOddHeaders/>` in `word/settings.xml` (without it Word ignores
 * the even headers).
 *
 * In the simple case the whole document is a single section. Multi-section
 * documents for a mid-document change of orientation or margins are out of
 * scope for v1.
 */
final readonly class Section
{
    /**
     * @param  list<BlockElement>  $body
     * @param  list<BlockElement>  $header  Default header.
     * @param  list<BlockElement>  $footer  Default footer.
     * @param  list<BlockElement>  $firstHeader  Header for the first page only
     *                                           (when non-empty, sectPr gets
     *                                           titlePg).
     * @param  list<BlockElement>  $firstFooter  Footer for the first page only.
     * @param  list<BlockElement>  $evenHeader  Header for even pages (requires
     *                                          evenAndOddHeaders in
     *                                          settings.xml — the writer adds
     *                                          it automatically).
     * @param  list<BlockElement>  $evenFooter  Footer for even pages.
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
     * All non-empty headers by type (default/first/even).
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
     * All non-empty footers by type.
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
