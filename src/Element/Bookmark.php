<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Element;

/**
 * Bookmark anchor — точка назначения для внутренней Hyperlink.
 *
 * Маппится в `<w:bookmarkStart w:id="N" w:name="anchorName"/>` +
 * `<w:bookmarkEnd w:id="N"/>` (Word требует обе границы; для inline-точечного
 * bookmark обе ставятся подряд).
 *
 * Параметр $children — опциональный inline-контент, "обёрнутый" в bookmark.
 * Для simple-anchor (просто метка) — пустой массив.
 *
 * Имена bookmark'ов в OOXML:
 *  - до 40 символов
 *  - начинаются с letter (не digit)
 *  - без пробелов и спецсимволов кроме _
 * Если HTML id не соответствует — Converter sanitiz'ит его (см. Converter).
 */
final readonly class Bookmark implements InlineElement
{
    /**
     * @param  list<InlineElement>  $children
     */
    public function __construct(
        public string $name,
        public array $children = [],
    ) {}
}
