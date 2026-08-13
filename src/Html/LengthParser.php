<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Html;

/**
 * A CSS length parser. Converts values with units into OOXML measurements.
 *
 * CSS units → twips:
 *   1pt   = 20 twips
 *   1px   = 15 twips (96 DPI screen, 72 pt/inch → 1px = 0.75pt = 15 twips)
 *   1in   = 1440 twips
 *   1cm   = 567 twips (1cm ≈ 28.3pt)
 *   1mm   = 56.7 twips
 *   1em   = ~10pt = 200 twips (rough; it depends on the font-size)
 *
 * A percentage (`50%`) comes back as `null` from parseTwips() and separately
 * through parsePercent() — the caller decides how to use it in its context.
 */
final class LengthParser
{
    /**
     * Parses an absolute length into twips. Returns null when the value is a
     * percentage, auto, inherit, or junk.
     */
    public static function parseTwips(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }
        $value = strtolower(trim($value));
        if ($value === '' || $value === 'auto' || $value === 'inherit' || str_ends_with($value, '%')) {
            return null;
        }

        if (preg_match('/^(-?\d+(?:\.\d+)?)\s*([a-z]+)?$/', $value, $m) !== 1) {
            return null;
        }
        $num = (float) $m[1];
        $unit = $m[2] ?? 'px';

        return match ($unit) {
            'pt' => (int) round($num * 20),
            'px' => (int) round($num * 15),
            'in' => (int) round($num * 1440),
            'cm' => (int) round($num * 567),
            'mm' => (int) round($num * 56.6929),
            'em' => (int) round($num * 200),
            'rem' => (int) round($num * 200),
            'dxa', 'twip' => (int) round($num),
            default => null,
        };
    }

    /**
     * Parses a percentage `50%` into a float (50.0). Null when it is not a %.
     */
    public static function parsePercent(?string $value): ?float
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if (preg_match('/^(-?\d+(?:\.\d+)?)\s*%$/', $value, $m) !== 1) {
            return null;
        }

        return (float) $m[1];
    }

    /**
     * OOXML's `<w:tcW w:type="pct"/>` uses value × 50 (50% = 2500).
     */
    public static function percentToOoxmlPct(float $percent): int
    {
        return (int) round($percent * 50);
    }

    /**
     * Converts twips into the half-points of `<w:sz>` (font-size).
     * 12pt = 240 twips = 24 half-points.
     */
    public static function twipsToHalfPoints(int $twips): int
    {
        return (int) round($twips / 10);
    }

    /**
     * Parses a font-size into half-points (for `<w:sz>`). Null when it is a %.
     */
    public static function parseFontSizeHalfPoints(?string $value): ?int
    {
        $twips = self::parseTwips($value);

        return $twips === null ? null : self::twipsToHalfPoints($twips);
    }

    /**
     * Parses a CSS length into EMU (for image dimensions).
     * 1 inch = 914400 EMU; 1 twip = 1/1440 inch → 1 twip = 635 EMU.
     */
    public static function parseEmu(?string $value): ?int
    {
        $twips = self::parseTwips($value);

        return $twips === null ? null : $twips * 635;
    }
}
