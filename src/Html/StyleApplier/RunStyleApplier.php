<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Html\StyleApplier;

use Dskripchenko\PhpDocx\Html\ColorParser;
use Dskripchenko\PhpDocx\Html\LengthParser;
use Dskripchenko\PhpDocx\Style\RunStyle;

/**
 * Применяет CSS-properties к RunStyle (`<w:rPr>`).
 *
 * Поддерживает inline-стили которые имеют смысл на run-уровне:
 *  - font-size, font-family, color, background-color
 *  - font-weight (`bold`/`700`/`bolder` → bold)
 *  - font-style (`italic`/`oblique`)
 *  - text-decoration (`underline`/`line-through`)
 *  - vertical-align (`sub`/`super`)
 *  - letter-spacing (разрядка)
 */
final class RunStyleApplier
{
    /**
     * @param  array<string, string>  $properties
     */
    public static function apply(RunStyle $base, array $properties): RunStyle
    {
        $size = $base->sizeHalfPoints;
        $color = $base->color;
        $bgColor = $base->backgroundColor;
        $fontFamily = $base->fontFamily;
        $bold = $base->bold;
        $italic = $base->italic;
        $underline = $base->underline;
        $strikethrough = $base->strikethrough;
        $superscript = $base->superscript;
        $subscript = $base->subscript;
        $letterSpacing = $base->letterSpacingTwips;

        foreach ($properties as $prop => $value) {
            switch ($prop) {
                case 'font-size':
                    $hp = LengthParser::parseFontSizeHalfPoints($value);
                    if ($hp !== null) {
                        $size = $hp;
                    }

                    break;

                case 'color':
                    $hex = ColorParser::parse($value);
                    if ($hex !== null) {
                        $color = $hex;
                    }

                    break;

                case 'background-color':
                case 'background':
                    // CSS shorthand `background: red` (без image) = background-color.
                    $hex = ColorParser::parse($value);
                    if ($hex !== null) {
                        $bgColor = $hex;
                    }

                    break;

                case 'font-family':
                    // CSS список «Inter, Helvetica, sans-serif» → берём первый
                    // не-generic font name.
                    $first = trim(explode(',', $value)[0] ?? '');
                    $first = trim($first, '\'"');
                    if ($first !== '') {
                        $fontFamily = $first;
                    }

                    break;

                case 'font-weight':
                    $bold = self::isBoldWeight($value);

                    break;

                case 'font-style':
                    $italic = in_array(strtolower(trim($value)), ['italic', 'oblique'], true);

                    break;

                case 'text-decoration':
                case 'text-decoration-line':
                    $val = strtolower($value);
                    if (str_contains($val, 'underline')) {
                        $underline = true;
                    }
                    if (str_contains($val, 'line-through')) {
                        $strikethrough = true;
                    }

                    break;

                case 'letter-spacing':
                    if (strtolower(trim($value)) === 'normal') {
                        $letterSpacing = null;

                        break;
                    }
                    $t = LengthParser::parseTwips($value);
                    if ($t !== null) {
                        $letterSpacing = $t;
                    }

                    break;

                case 'vertical-align':
                    $val = strtolower(trim($value));
                    if ($val === 'super') {
                        $superscript = true;
                        $subscript = false;
                    } elseif ($val === 'sub') {
                        $subscript = true;
                        $superscript = false;
                    }

                    break;
            }
        }

        return new RunStyle(
            sizeHalfPoints: $size,
            color: $color,
            backgroundColor: $bgColor,
            fontFamily: $fontFamily,
            bold: $bold,
            italic: $italic,
            underline: $underline,
            strikethrough: $strikethrough,
            superscript: $superscript,
            subscript: $subscript,
            // highlight CSS не задаёт — переносим из базы, иначе любой
            // inline-стиль на вложенном теге стирал подсветку родителя.
            highlight: $base->highlight,
            letterSpacingTwips: $letterSpacing,
        );
    }

    private static function isBoldWeight(string $value): bool
    {
        $v = strtolower(trim($value));
        if ($v === 'bold' || $v === 'bolder') {
            return true;
        }
        if (is_numeric($v)) {
            return (int) $v >= 600;
        }

        return false;
    }
}
