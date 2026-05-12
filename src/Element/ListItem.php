<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Element;

/**
 * Элемент списка `<li>`. Содержит inline content + опциональный nested
 * ListNode (для вложенных uls).
 */
final readonly class ListItem
{
    /**
     * @param  list<InlineElement>  $children
     */
    public function __construct(
        public array $children,
        public ?ListNode $nestedList = null,
    ) {}
}
