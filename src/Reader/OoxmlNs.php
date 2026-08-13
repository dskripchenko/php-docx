<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Reader;

/**
 * OOXML namespaces — a wrapper over DOMElement for working with tag
 * namespaces.
 *
 * `<w:p>` tags live in the `urn:schemas-...wordprocessingml...` namespace,
 * `<a:srgbClr>` in DrawingML, and so on. DOMElement::getElementsByTagName()
 * takes a prefixed name, getElementsByTagNameNS() a namespace URI.
 *
 * The NS URIs are used because DOCX generators differ in the prefixes they
 * pick (`w` versus `ns0`).
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
     * Finds every child element with the given NS+localName in the given parent
     * node (not recursively — direct children only).
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
     * The first child with the given NS+localName, or null.
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
     * The default is true (a missing val means on).
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
     * Reads the `w:val` attribute (or null).
     */
    public static function wVal(\DOMElement $el): ?string
    {
        return $el->hasAttributeNS(self::W, 'val')
            ? $el->getAttributeNS(self::W, 'val')
            : null;
    }
}
