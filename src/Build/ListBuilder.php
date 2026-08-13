<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Build;

use Dskripchenko\PhpDocx\Element\ListFormat;
use Dskripchenko\PhpDocx\Element\ListItem;
use Dskripchenko\PhpDocx\Element\ListNode;

/**
 * A fluent builder for a list (`<ul>`/`<ol>`).
 *
 * Used through `$doc->bulletList(fn($l) => ...)` or
 * `$doc->orderedList(fn($l) => $l->format(ListFormat::LowerLetter)->...)`.
 *
 * Item nesting:
 *   $l->item('First')
 *     ->item('Second', fn($nested) => $nested->item('Sub A')->item('Sub B'))
 *
 * A nested list inherits $ordered from its parent by default; format and
 * startAt can be changed inside the nested builder.
 */
final class ListBuilder
{
    /** @var list<ListItem> */
    private array $items = [];

    private ?ListFormat $format = null;

    private int $startAt = 1;

    public function __construct(
        private readonly bool $ordered = false,
    ) {}

    /**
     * Adds a list item.
     *
     * @param  string|callable(ListItemBuilder): void  $contentOrBuilder
     *         a string is the short form (a single run of text)
     *         a callable is the full builder for the item content
     * @param  callable(ListBuilder): void|null  $nestedCallback
     *         when given, a nested list is created for the item (inheriting
     *         ordered from the parent by default).
     */
    public function item(string|callable $contentOrBuilder, ?callable $nestedCallback = null): self
    {
        $itemBuilder = new ListItemBuilder;
        if (is_string($contentOrBuilder)) {
            $itemBuilder->text($contentOrBuilder);
        } else {
            $contentOrBuilder($itemBuilder);
        }

        if ($nestedCallback !== null) {
            $nestedBuilder = new ListBuilder($this->ordered);
            $nestedCallback($nestedBuilder);
            $itemBuilder->withNested($nestedBuilder->build());
        }

        $this->items[] = $itemBuilder->build();

        return $this;
    }

    public function format(ListFormat $format): self
    {
        $this->format = $format;

        return $this;
    }

    public function startAt(int $start): self
    {
        $this->startAt = max(1, $start);

        return $this;
    }

    public function build(): ListNode
    {
        return new ListNode(
            items: $this->items,
            ordered: $this->ordered,
            levelStart: 0,
            format: $this->format,
            startAt: $this->startAt,
        );
    }
}
