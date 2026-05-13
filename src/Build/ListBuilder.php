<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Build;

use Dskripchenko\PhpDocx\Element\ListFormat;
use Dskripchenko\PhpDocx\Element\ListItem;
use Dskripchenko\PhpDocx\Element\ListNode;

/**
 * Fluent builder для списка (`<ul>`/`<ol>`).
 *
 * Используется через `$doc->bulletList(fn($l) => ...)` или
 * `$doc->orderedList(fn($l) => $l->format(ListFormat::LowerLetter)->...)`.
 *
 * Item nesting:
 *   $l->item('First')
 *     ->item('Second', fn($nested) => $nested->item('Sub A')->item('Sub B'))
 *
 * Nested list по умолчанию наследует $ordered от parent'а; внутри nested
 * builder'а можно поменять format/startAt.
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
     * Добавить пункт списка.
     *
     * @param  string|callable(ListItemBuilder): void  $contentOrBuilder
     *         string — short-form (один Run с текстом)
     *         callable — full builder для item content'а
     * @param  callable(ListBuilder): void|null  $nestedCallback
     *         Если задан — для item создаётся nested list (по умолчанию
     *         наследует ordered от parent'а).
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
