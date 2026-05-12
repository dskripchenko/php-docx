<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Writer;

/**
 * Минимальный helper для XML-escape'инга text content и attribute values.
 *
 * OOXML XML — UTF-8, нужно эскейпить `& < > " '` в content/attr-values.
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
