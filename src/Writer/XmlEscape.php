<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Writer;

/**
 * A minimal helper for XML-escaping text content and attribute values.
 *
 * OOXML XML is UTF-8; `& < > " '` need escaping in content and attribute
 * values.
 */
final class XmlEscape
{
    public static function text(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    public static function attr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
