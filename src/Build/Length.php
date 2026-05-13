<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Build;

/**
 * Длиновые конверсии в OOXML-нативные twips.
 *
 * OOXML использует twips (1/20 пункта) как универсальную единицу для
 * margin/padding/width/spacing. Этот класс — единая точка перевода
 * привычных единиц в twips.
 *
 * Коэффициенты:
 *  - 1 pt = 20 twips
 *  - 1 in = 72 pt = 1440 twips
 *  - 1 cm = 72 / 2.54 pt = 56.6929... × 10 = 566.929 twips
 *  - 1 mm = 56.6929 twips (1/10 cm)
 *  - 1 px (@96 DPI, CSS standard) = 0.75 pt = 15 twips
 *
 * EMU-related (для wp:extent в drawings): 1 in = 914400 EMU; 1 px = 9525 EMU.
 * Здесь не покрываем — для drawings есть Image::fromPx.
 *
 * Используется через TableBuilder/TableCellBuilder/ParagraphBuilder
 * `*Mm/*Cm/*Pt/*Inches`-методы или напрямую: `Length::cm(2.5)`.
 */
final class Length
{
    /**
     * Identity — int twips сразу.
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
