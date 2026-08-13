<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Element;

/**
 * A footnote — the mark in the line and its text at the foot of the page.
 *
 * In the container these are two different parts: `w:footnoteReference` in the
 * text and the text itself in `word/footnotes.xml`. Here they are already
 * brought together: the consumer needs the whole footnote, not a reference to
 * it.
 */
final readonly class Footnote implements InlineElement
{
    public function __construct(public string $content) {}
}
