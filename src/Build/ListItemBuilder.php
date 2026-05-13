<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Build;

use Dskripchenko\PhpDocx\Element\ListItem;
use Dskripchenko\PhpDocx\Element\ListNode;
use Dskripchenko\PhpDocx\Style\RunStyle;

/**
 * Fluent builder для отдельного `<li>`. Inline-content API через
 * AddsInlineContent (тот же что у ParagraphBuilder); плюс optional
 * nested list.
 */
final class ListItemBuilder
{
    use AddsInlineContent;

    private ?ListNode $nested = null;

    public function __construct(?RunStyle $defaultRunStyle = null)
    {
        $this->defaultRunStyle = $defaultRunStyle ?? new RunStyle;
    }

    public function withNested(?ListNode $list): self
    {
        $this->nested = $list;

        return $this;
    }

    public function build(): ListItem
    {
        return new ListItem(
            children: $this->children,
            nestedList: $this->nested,
        );
    }
}
