<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Element;

/**
 * A bookmark anchor — the destination of an internal hyperlink.
 *
 * It maps to `<w:bookmarkStart w:id="N" w:name="anchorName"/>` +
 * `<w:bookmarkEnd w:id="N"/>` (Word requires both boundaries; for a point-like
 * inline bookmark the two go one after the other).
 *
 * The $children parameter is the optional inline content wrapped in the
 * bookmark. For a simple anchor (a bare marker) it is an empty array.
 *
 * Bookmark names in OOXML:
 *  - up to 40 characters
 *  - start with a letter (not a digit)
 *  - no spaces or special characters except _
 * An HTML id that does not comply is sanitized by the converter (see
 * Converter).
 */
final readonly class Bookmark implements InlineElement
{
    /**
     * @param  list<InlineElement>  $children
     */
    public function __construct(
        public string $name,
        public array $children = [],
    ) {}
}
