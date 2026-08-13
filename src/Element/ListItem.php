<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Element;

use Dskripchenko\PhpDocx\Style\ParagraphStyle;

/**
 * A list item `<li>`. It holds inline content plus an optional nested ListNode
 * (for nested lists).
 */
final readonly class ListItem
{
    /**
     * @param  list<InlineElement>  $children
     */
    public function __construct(
        public array $children,
        public ?ListNode $nestedList = null,
        /**
         * The paragraph style of the item.
         *
         * A list item in Word is an ordinary paragraph carrying numbering, and
         * it holds all the same things: alignment, indents, spacing, "keep with
         * the next". While the style was dropped, section headings formatted as
         * a numbered list lost their `w:keepNext` too — and the document
         * diverged from the original in how it broke into pages.
         */
        public ParagraphStyle $style = new ParagraphStyle,
    ) {}
}
