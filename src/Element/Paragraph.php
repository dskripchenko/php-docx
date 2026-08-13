<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Element;

use Dskripchenko\PhpDocx\Style\ParagraphStyle;

/**
 * A paragraph — `<w:p>` in OOXML. It holds inline elements
 * (Run/LineBreak/Hyperlink).
 *
 * The heading level 1..6 is optional: the emitter picks the matching paragraph
 * style (Heading1..Heading6) and applies the default styles.
 */
final readonly class Paragraph implements BlockElement
{
    /**
     * @param  list<InlineElement>  $children
     * @param  int|null  $headingLevel  1..6 when this is a heading, null otherwise
     */
    public function __construct(
        public array $children,
        public ParagraphStyle $style = new ParagraphStyle,
        public ?int $headingLevel = null,
    ) {}
}
