<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Writer;

/**
 * A minimal helper for XML-escaping text content and attribute values.
 *
 * OOXML XML is UTF-8; `& < > " '` need escaping in content and attribute
 * values. The result is always well-formed: an invalid UTF-8 sequence becomes
 * U+FFFD, and a character XML 1.0 does not allow (a C0 control other than
 * tab, newline and carriage return, U+FFFE, U+FFFF) is removed — Word refuses
 * to open a part that contains one.
 */
final class XmlEscape
{
    /**
     * Everything outside the XML 1.0 `Char` production.
     */
    private const NOT_XML_CHAR = '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u';

    public static function text(string $value): string
    {
        return self::escape($value);
    }

    public static function attr(string $value): string
    {
        return self::escape($value);
    }

    private static function escape(string $value): string
    {
        // ENT_SUBSTITUTE: without it an invalid sequence makes
        // htmlspecialchars return '' and the whole string is lost.
        $escaped = htmlspecialchars($value, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');

        return preg_replace(self::NOT_XML_CHAR, '', $escaped) ?? $escaped;
    }
}
