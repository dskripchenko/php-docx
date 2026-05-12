<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Html;

/**
 * CSS color → OOXML hex string (6 chars uppercase or lowercase, без `#`).
 *
 * Поддерживает:
 *  - `#fff`, `#FFFFFF` — hex
 *  - `rgb(255,255,255)`, `rgb(255, 255, 255)` — rgb function
 *  - named colors (минимальный set из CSS Level 1)
 *
 * `transparent`/`inherit`/`initial` → null (caller игнорирует).
 */
final class ColorParser
{
    /**
     * @var array<string, string>
     */
    private const NAMED = [
        'black' => '000000',
        'silver' => 'c0c0c0',
        'gray' => '808080',
        'grey' => '808080',
        'white' => 'ffffff',
        'maroon' => '800000',
        'red' => 'ff0000',
        'purple' => '800080',
        'fuchsia' => 'ff00ff',
        'green' => '008000',
        'lime' => '00ff00',
        'olive' => '808000',
        'yellow' => 'ffff00',
        'navy' => '000080',
        'blue' => '0000ff',
        'teal' => '008080',
        'aqua' => '00ffff',
        'cyan' => '00ffff',
        'orange' => 'ffa500',
    ];

    public static function parse(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = strtolower(trim($value));
        if ($value === '' || $value === 'transparent' || $value === 'inherit' || $value === 'initial' || $value === 'currentcolor') {
            return null;
        }

        // #rgb / #rrggbb
        if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $value, $m) === 1) {
            $hex = $m[1];
            if (strlen($hex) === 3) {
                // expand shorthand: f0a → ff00aa
                $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
            }

            return $hex;
        }

        // rgb(r,g,b) / rgb(r g b)
        if (preg_match('/^rgba?\(\s*(\d+)[\s,]+(\d+)[\s,]+(\d+)/', $value, $m) === 1) {
            $r = max(0, min(255, (int) $m[1]));
            $g = max(0, min(255, (int) $m[2]));
            $b = max(0, min(255, (int) $m[3]));

            return sprintf('%02x%02x%02x', $r, $g, $b);
        }

        // named color
        return self::NAMED[$value] ?? null;
    }
}
