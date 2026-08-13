<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Element;

/**
 * A hyperlink — external (a URL) or internal (an anchor → a bookmark).
 *
 * External: `<w:hyperlink r:id="rIdN"/>` plus an entry in document.xml.rels.
 * Internal: `<w:hyperlink w:anchor="bookmarkName"/>` (no rel).
 *
 * An internal link works only when the document holds a Bookmark with the
 * matching name (see Element\Bookmark).
 */
final readonly class Hyperlink implements InlineElement
{
    /**
     * @param  string|null  $href  The external URL. Null for an internal link.
     * @param  list<InlineElement>  $children  The content inside the link.
     * @param  string|null  $anchor  The bookmark name (without the `#`). Null
     *                               for an external link.
     */
    public function __construct(
        public ?string $href,
        public array $children,
        public ?string $anchor = null,
    ) {}

    public function isInternal(): bool
    {
        return $this->anchor !== null;
    }

    /**
     * The constructor for an internal link to a bookmark.
     *
     * @param  list<InlineElement>  $children
     */
    public static function internal(string $anchor, array $children): self
    {
        return new self(href: null, children: $children, anchor: $anchor);
    }
}
