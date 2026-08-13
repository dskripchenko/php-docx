<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Build;

/**
 * Length conversions into the twips OOXML speaks natively.
 *
 * OOXML uses twips (1/20 of a point) as the universal unit for
 * margin/padding/width/spacing. This class is the single place where familiar
 * units are converted into twips.
 *
 * The factors:
 *  - 1 pt = 20 twips
 *  - 1 in = 72 pt = 1440 twips
 *  - 1 cm = 72 / 2.54 pt = 56.6929... × 10 = 566.929 twips
 *  - 1 mm = 56.6929 twips (1/10 cm)
 *  - 1 px (@96 DPI, CSS standard) = 0.75 pt = 15 twips
 *
 * EMU-related (for wp:extent in drawings): 1 in = 914400 EMU; 1 px = 9525 EMU.
 * Not covered here — drawings have Image::fromPx.
 *
 * Used through the `*Mm/*Cm/*Pt/*Inches` methods of
 * TableBuilder/TableCellBuilder/ParagraphBuilder, or directly:
 * `Length::cm(2.5)`.
 */
final class Length
{
    /**
     * Identity — twips as an int already.
     */
    public static function twips(int $twips): int
    {
        return $twips;
    }

    public static function pt(float $points): int
    {
        return (int) round($points * 20);
    }

    public static function mm(float $millimeters): int
    {
        // 1 mm = 1/10 cm = 56.6929 twips (more precisely 56.69291339).
        return (int) round($millimeters * 56.6929133858);
    }

    public static function cm(float $centimeters): int
    {
        return (int) round($centimeters * 566.929133858);
    }

    public static function inch(float $inches): int
    {
        return (int) round($inches * 1440);
    }

    /**
     * CSS px (96 DPI). 1 px = 15 twips.
     */
    public static function px(float $pixels): int
    {
        return (int) round($pixels * 15);
    }
}
