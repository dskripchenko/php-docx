<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Element;

use Dskripchenko\PhpDocx\Style\RunStyle;

/**
 * A run — a contiguous piece of text with uniform formatting.
 * It maps to `<w:r><w:rPr/><w:t>...</w:t></w:r>`.
 */
final readonly class Run implements InlineElement
{
    public function __construct(
        public string $text,
        public RunStyle $style = new RunStyle,
    ) {}
}
