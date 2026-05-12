<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Element;

use Dskripchenko\PhpDocx\Style\ParagraphStyle;

/**
 * Параграф — `<w:p>` в OOXML. Содержит inline-элементы (Run/LineBreak/Hyperlink).
 *
 * Heading-level 1..6 опционально: эмиттер выберет соответствующий
 * paragraph-style (Heading1..Heading6) и применит default-стили.
 */
final readonly class Paragraph implements BlockElement
{
    /**
     * @param  list<InlineElement>  $children
     * @param  int|null  $headingLevel  1..6 если это заголовок, иначе null
     */
    public function __construct(
        public array $children,
        public ParagraphStyle $style = new ParagraphStyle,
        public ?int $headingLevel = null,
    ) {}
}
