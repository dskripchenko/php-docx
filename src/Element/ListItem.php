<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Element;

use Dskripchenko\PhpDocx\Style\ParagraphStyle;

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
        /**
         * Стиль абзаца пункта.
         *
         * Пункт списка в Word — обычный абзац с нумерацией, и он несёт всё то
         * же: выравнивание, отступы, интервалы, «не отрывать от следующего».
         * Пока стиль терялся, заголовки разделов, оформленные номерным
         * списком, теряли и `w:keepNext` — документ расходился с оригиналом
         * по разбивке на страницы.
         */
        public ParagraphStyle $style = new ParagraphStyle,
    ) {}
}
