<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Html;

use Dskripchenko\PhpDocx\Style\Border;
use Dskripchenko\PhpDocx\Style\BorderStyle;

/**
 * Parses the CSS `border: <width> <style> <color>` (in any order) into a Border
 * value object. `none`/`0` gives a Border with the None style.
 */
final class BorderParser
{
    public static function parse(string $value): Border
    {
        $v = strtolower(trim($value));
        if ($v === 'none' || $v === '0' || str_starts_with($v, '0 ')) {
            return new Border(BorderStyle::None);
        }

        $parts = preg_split('/\s+/', $v) ?: [];
        $size = 4;        // default 0.5pt (4 восьмых)
        $color = '000000';
        $style = BorderStyle::Single;

        foreach ($parts as $p) {
            $hex = ColorParser::parse($p);
            if ($hex !== null) {
                $color = $hex;

                continue;
            }
            if (preg_match('/^(\d+(?:\.\d+)?)(px|pt)?$/', $p, $m) === 1) {
                $num = (float) $m[1];
                $unit = $m[2] ?? 'px';
                // 1pt = 8 eighths; 1px ≈ 0.75pt → 6 eighths.
                $size = $unit === 'pt' ? (int) round($num * 8) : (int) round($num * 6);

                continue;
            }
            $mapped = match ($p) {
                'solid' => BorderStyle::Single,
                'double' => BorderStyle::Double,
                'dashed' => BorderStyle::Dashed,
                'dotted' => BorderStyle::Dotted,
                'thick' => BorderStyle::Thick,
                default => null,
            };
            if ($mapped !== null) {
                $style = $mapped;
            }
        }

        return new Border($style, max(2, $size), $color);
    }
}
