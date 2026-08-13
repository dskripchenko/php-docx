<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Html;

/**
 * Parses the value of a `style="..."` attribute into an associative array
 * `[property => value]`. It does not parse CSS rules — flat declarations only.
 *
 *   parse('color: red; font-size: 12pt;')
 *     → ['color' => 'red', 'font-size' => '12pt']
 *
 * Multi-value properties (`background: red`) are kept raw; converting a
 * shorthand into the long form (`padding: 4pt 8pt` → top/right/bottom/left) is
 * done in the style appliers.
 */
final class InlineStyleParser
{
    /**
     * @return array<string, string>
     */
    public static function parse(?string $style): array
    {
        if ($style === null || trim($style) === '') {
            return [];
        }

        $out = [];
        foreach (explode(';', $style) as $decl) {
            $decl = trim($decl);
            if ($decl === '' || ! str_contains($decl, ':')) {
                continue;
            }
            [$prop, $value] = explode(':', $decl, 2);
            $prop = strtolower(trim($prop));
            $value = trim($value);
            if ($prop === '' || $value === '') {
                continue;
            }
            $out[$prop] = $value;
        }

        return $out;
    }
}
