<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Html\StyleApplier;

use Dskripchenko\PhpDocx\Html\BorderParser;
use Dskripchenko\PhpDocx\Html\ColorParser;
use Dskripchenko\PhpDocx\Html\LengthParser;
use Dskripchenko\PhpDocx\Style\BorderSet;
use Dskripchenko\PhpDocx\Style\CellStyle;
use Dskripchenko\PhpDocx\Style\VerticalAlign;

/**
 * Применяет CSS к CellStyle. Парсит:
 *  - width (% или абсолют)
 *  - padding shorthand + per-side
 *  - background-color
 *  - vertical-align
 *  - border shorthand + per-side
 */
final class CellStyleApplier
{
    /**
     * @param  array<string, string>  $properties
     * @param  array<string, string>  $attrs  HTML атрибуты td/th (colspan, rowspan, width, valign, bgcolor)
     */
    public static function apply(CellStyle $base, array $properties, array $attrs = []): CellStyle
    {
        $widthTwips = $base->widthTwips;
        $widthPercent = $base->widthPercent;
        $padTop = $base->paddingTopTwips;
        $padRight = $base->paddingRightTwips;
        $padBottom = $base->paddingBottomTwips;
        $padLeft = $base->paddingLeftTwips;
        $vAlign = $base->verticalAlign;
        $bgColor = $base->backgroundColor;
        $borders = $base->borders;
        $gridSpan = (int) ($attrs['colspan'] ?? $base->gridSpan);
        $rowSpan = (int) ($attrs['rowspan'] ?? $base->rowSpan);

        // HTML legacy attrs
        if (isset($attrs['width'])) {
            self::applyWidth($attrs['width'], $widthTwips, $widthPercent);
        }
        if (isset($attrs['valign'])) {
            $vAlign = self::mapVAlign($attrs['valign']) ?? $vAlign;
        }
        if (isset($attrs['bgcolor'])) {
            $hex = ColorParser::parse($attrs['bgcolor']);
            if ($hex !== null) {
                $bgColor = $hex;
            }
        }

        foreach ($properties as $prop => $value) {
            switch ($prop) {
                case 'width':
                    self::applyWidth($value, $widthTwips, $widthPercent);

                    break;

                case 'padding':
                    [$t, $r, $b, $l] = self::expandFourSides($value);
                    if ($t !== null) {
                        $padTop = $t;
                    }
                    if ($r !== null) {
                        $padRight = $r;
                    }
                    if ($b !== null) {
                        $padBottom = $b;
                    }
                    if ($l !== null) {
                        $padLeft = $l;
                    }

                    break;

                case 'padding-top':    $padTop = LengthParser::parseTwips($value) ?? $padTop;       break;
                case 'padding-right':  $padRight = LengthParser::parseTwips($value) ?? $padRight;   break;
                case 'padding-bottom': $padBottom = LengthParser::parseTwips($value) ?? $padBottom; break;
                case 'padding-left':   $padLeft = LengthParser::parseTwips($value) ?? $padLeft;     break;

                case 'background-color':
                case 'background':
                    $hex = ColorParser::parse($value);
                    if ($hex !== null) {
                        $bgColor = $hex;
                    }

                    break;

                case 'vertical-align':
                    $vAlign = self::mapVAlign($value) ?? $vAlign;

                    break;

                case 'border':
                    $b = BorderParser::parse($value);
                    $borders = new BorderSet($b, $b, $b, $b);

                    break;

                case 'border-top':    $borders = self::patchBorder($borders, 'top', $value); break;
                case 'border-right':  $borders = self::patchBorder($borders, 'right', $value); break;
                case 'border-bottom': $borders = self::patchBorder($borders, 'bottom', $value); break;
                case 'border-left':   $borders = self::patchBorder($borders, 'left', $value); break;
            }
        }

        return new CellStyle(
            widthTwips: $widthTwips,
            widthPercent: $widthPercent,
            paddingTopTwips: $padTop,
            paddingRightTwips: $padRight,
            paddingBottomTwips: $padBottom,
            paddingLeftTwips: $padLeft,
            verticalAlign: $vAlign,
            backgroundColor: $bgColor,
            borders: $borders,
            gridSpan: max(1, $gridSpan),
            rowSpan: max(1, $rowSpan),
        );
    }

    private static function applyWidth(string $value, ?int &$widthTwips, ?int &$widthPercent): void
    {
        $pct = LengthParser::parsePercent($value);
        if ($pct !== null) {
            $widthPercent = LengthParser::percentToOoxmlPct($pct);
            $widthTwips = null;

            return;
        }
        $t = LengthParser::parseTwips($value);
        if ($t !== null) {
            $widthTwips = $t;
            $widthPercent = null;
        }
    }

    private static function mapVAlign(string $value): ?VerticalAlign
    {
        return match (strtolower(trim($value))) {
            'top' => VerticalAlign::Top,
            'middle', 'center' => VerticalAlign::Center,
            'bottom' => VerticalAlign::Bottom,
            default => null,
        };
    }

    private static function patchBorder(?BorderSet $set, string $side, string $value): BorderSet
    {
        $b = BorderParser::parse($value);
        $set ??= new BorderSet;

        return new BorderSet(
            top: $side === 'top' ? $b : $set->top,
            right: $side === 'right' ? $b : $set->right,
            bottom: $side === 'bottom' ? $b : $set->bottom,
            left: $side === 'left' ? $b : $set->left,
            insideH: $set->insideH,
            insideV: $set->insideV,
        );
    }

    /**
     * @return array{?int, ?int, ?int, ?int}
     */
    private static function expandFourSides(string $value): array
    {
        $parts = preg_split('/\s+/', trim($value)) ?: [];
        $t = array_map(fn (string $p): ?int => LengthParser::parseTwips($p), $parts);

        return match (count($t)) {
            1 => [$t[0], $t[0], $t[0], $t[0]],
            2 => [$t[0], $t[1], $t[0], $t[1]],
            3 => [$t[0], $t[1], $t[2], $t[1]],
            4 => [$t[0], $t[1], $t[2], $t[3]],
            default => [null, null, null, null],
        };
    }
}
