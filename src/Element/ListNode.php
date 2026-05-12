<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Element;

/**
 * Список — `<w:p>` параграфы с `<w:numPr>` reference на abstract numbering.
 *
 * Поддержка:
 *  - bullet (●/○/■ для 3 уровней)
 *  - ordered с гибкими форматами через ListFormat (decimal/lowerLetter/
 *    upperLetter/lowerRoman/upperRoman) и custom $startAt
 *  - nested через ListItem.nestedList
 */
final readonly class ListNode implements BlockElement
{
    /**
     * @param  list<ListItem>  $items
     * @param  bool  $ordered  true → ordered, false → bullet
     * @param  int  $levelStart  Уровень вложения (0..2)
     * @param  ListFormat|null  $format  Override format. Если null —
     *                                   default Bullet/Decimal по $ordered.
     * @param  int  $startAt  С какого номера начать (default 1).
     *                       Игнорируется для bullet.
     */
    public function __construct(
        public array $items,
        public bool $ordered = false,
        public int $levelStart = 0,
        public ?ListFormat $format = null,
        public int $startAt = 1,
    ) {}

    public function effectiveFormat(): ListFormat
    {
        return $this->format ?? ($this->ordered ? ListFormat::Decimal : ListFormat::Bullet);
    }
}
