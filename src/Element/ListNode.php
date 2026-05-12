<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Element;

/**
 * Список — `<w:p>` параграфы с `<w:numPr>` reference на abstract numbering.
 *
 * Phase 5b: bullet (●/○/■) и ordered (decimal/lower-alpha/lower-roman) — три
 * уровня вложения для каждого типа.
 */
final readonly class ListNode implements BlockElement
{
    /**
     * @param  list<ListItem>  $items
     * @param  int  $levelStart  Уровень вложения (0..2). Внешний список — 0.
     */
    public function __construct(
        public array $items,
        public bool $ordered = false,
        public int $levelStart = 0,
    ) {}
}
