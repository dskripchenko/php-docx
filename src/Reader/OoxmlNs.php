<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Reader;

/**
 * OOXML namespaces — обёртка над DOMElement для работы с tag namespaces.
 *
 * `<w:p>`-теги используют namespace `urn:schemas-...wordprocessingml...`,
 * `<a:srgbClr>` — DrawingML, etc. DOMElement::getElementsByTagName()
 * принимает префиксное имя, getElementsByTagNameNS — namespace URI.
 *
 * Используем NS URIs т.к. некоторые DOCX-генераторы используют разные
 * префиксы (`w` vs `ns0`).
 */
final class OoxmlNs
{
    public const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    public const R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    public const A = 'http://schemas.openxmlformats.org/drawingml/2006/main';

    public const PIC = 'http://schemas.openxmlformats.org/drawingml/2006/picture';

    public const WP = 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing';

    public const V = 'urn:schemas-microsoft-com:vml';

    public const O = 'urn:schemas-microsoft-com:office:office';

    public const M = 'http://schemas.openxmlformats.org/officeDocument/2006/math';

    public const XML = 'http://www.w3.org/XML/1998/namespace';

    /**
     * Найти все child-элементы конкретного NS+localName в указанной родительской ноде
     * (не рекурсивно — только прямые потомки).
     *
     * @return list<\DOMElement>
     */
    public static function children(\DOMElement $parent, string $ns, string $localName): array
    {
        $out = [];
        foreach ($parent->childNodes as $c) {
            if ($c instanceof \DOMElement
                && $c->namespaceURI === $ns
                && $c->localName === $localName) {
                $out[] = $c;
            }
        }

        return $out;
    }

    /**
     * Первый ребёнок с заданным NS+localName, или null.
     */
    public static function firstChild(\DOMElement $parent, string $ns, string $localName): ?\DOMElement
    {
        foreach ($parent->childNodes as $c) {
            if ($c instanceof \DOMElement
                && $c->namespaceURI === $ns
                && $c->localName === $localName) {
                return $c;
            }
        }

        return null;
    }

    /**
     * `<w:b w:val="0"/>` → false; `<w:b/>` → true; `<w:b w:val="1"/>` → true.
     * Default — true (отсутствие val = on).
     */
    public static function boolToggle(?\DOMElement $el): bool
    {
        if ($el === null) {
            return false;
        }
        if (! $el->hasAttributeNS(self::W, 'val')) {
            return true;
        }
        $val = $el->getAttributeNS(self::W, 'val');

        return match (strtolower($val)) {
            'false', '0', 'off' => false,
            default => true,
        };
    }

    /**
     * Получает атрибут `w:val` (или null).
     */
    public static function wVal(\DOMElement $el): ?string
    {
        return $el->hasAttributeNS(self::W, 'val')
            ? $el->getAttributeNS(self::W, 'val')
            : null;
    }
}
