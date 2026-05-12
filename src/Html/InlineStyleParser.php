<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Html;

/**
 * Разбирает значение `style="..."` атрибута в associative-массив
 * `[property => value]`. Не парсит CSS-rules — только flat declarations.
 *
 *   parse('color: red; font-size: 12pt;')
 *     → ['color' => 'red', 'font-size' => '12pt']
 *
 * Multi-value properties (`background: red`) сохраняются raw; конверсия
 * shorthand в long-form (`padding: 4pt 8pt` → top/right/bottom/left)
 * делается в StyleApplier'ах.
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
