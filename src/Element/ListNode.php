<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Element;

/**
 * A list — `<w:p>` paragraphs carrying a `<w:numPr>` reference to an abstract
 * numbering.
 *
 * Supported:
 *  - bullet (●/○/■ for 3 levels)
 *  - ordered, with flexible formats via ListFormat (decimal/lowerLetter/
 *    upperLetter/lowerRoman/upperRoman) and a custom $startAt
 *  - nesting via ListItem.nestedList
 */
final readonly class ListNode implements BlockElement
{
    /**
     * @param  list<ListItem>  $items
     * @param  bool  $ordered  true → ordered, false → bullet
     * @param  int  $levelStart  The nesting level (0..2)
     * @param  ListFormat|null  $format  An override format. Null means the
     *                                   Bullet/Decimal default implied by
     *                                   $ordered.
     * @param  int  $startAt  The number to start from (default 1). Ignored for
     *                       bullet lists.
     */
    public function __construct(
        public array $items,
        public bool $ordered = false,
        public int $levelStart = 0,
        public ?ListFormat $format = null,
        public int $startAt = 1,
    ) {}

    public function effectiveFormat(): ListFormat
    {
        return $this->format ?? ($this->ordered ? ListFormat::Decimal : ListFormat::Bullet);
    }
}
