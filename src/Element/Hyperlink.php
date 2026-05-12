<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Element;

/**
 * Внешняя ссылка. Маппится в `<w:hyperlink r:id="rIdN"/>` + entry
 * в `word/_rels/document.xml.rels`.
 */
final readonly class Hyperlink implements InlineElement
{
    /**
     * @param  list<InlineElement>  $children  Контент внутри ссылки.
     */
    public function __construct(
        public string $href,
        public array $children,
    ) {}
}
