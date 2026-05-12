<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Element;

use Dskripchenko\PhpDocx\Style\RunStyle;

/**
 * Run — непрерывный кусок текста с одинаковым оформлением.
 * Маппится в `<w:r><w:rPr/><w:t>...</w:t></w:r>`.
 */
final readonly class Run implements InlineElement
{
    public function __construct(
        public string $text,
        public RunStyle $style = new RunStyle,
    ) {}
}
