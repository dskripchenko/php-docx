<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Element;

/**
 * Гиперссылка — внешняя (URL) или внутренняя (anchor → bookmark).
 *
 * Внешняя: `<w:hyperlink r:id="rIdN"/>` + entry в document.xml.rels.
 * Внутренняя: `<w:hyperlink w:anchor="bookmarkName"/>` (без rel).
 *
 * Внутренняя ссылка работает только если в документе есть Bookmark
 * с соответствующим name (см. Element\Bookmark).
 */
final readonly class Hyperlink implements InlineElement
{
    /**
     * @param  string|null  $href  Внешний URL. Null если ссылка внутренняя.
     * @param  list<InlineElement>  $children  Контент внутри ссылки.
     * @param  string|null  $anchor  Имя bookmark'а (без `#`). Null для внешней.
     */
    public function __construct(
        public ?string $href,
        public array $children,
        public ?string $anchor = null,
    ) {}

    public function isInternal(): bool
    {
        return $this->anchor !== null;
    }

    /**
     * Конструктор для внутренней ссылки на bookmark.
     *
     * @param  list<InlineElement>  $children
     */
    public static function internal(string $anchor, array $children): self
    {
        return new self(href: null, children: $children, anchor: $anchor);
    }
}
