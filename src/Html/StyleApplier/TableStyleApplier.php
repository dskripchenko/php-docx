<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Html\StyleApplier;

use Dskripchenko\PhpDocx\Html\BorderParser;
use Dskripchenko\PhpDocx\Html\LengthParser;
use Dskripchenko\PhpDocx\Style\Alignment;
use Dskripchenko\PhpDocx\Style\BorderSet;
use Dskripchenko\PhpDocx\Style\TableStyle;

/**
 * Применяет CSS к TableStyle. Парсит width/border-collapse/table-layout.
 */
final class TableStyleApplier
{
    /**
     * @param  array<string, string>  $properties
     * @param  array<string, string>  $attrs  HTML attrs (border, width, align)
     */
    public static function apply(TableStyle $base, array $properties, array $attrs = []): TableStyle
    {
        $widthTwips = $base->widthTwips;
        $widthPercent = $base->widthPercent;
        $fixed = $base->fixedLayout;
        $borders = $base->borders;
        $align = $base->alignment;

        if (isset($attrs['width'])) {
            self::applyWidth($attrs['width'], $widthTwips, $widthPercent);
        }
        if (isset($attrs['align'])) {
            $align = self::mapAlign($attrs['align']) ?? $align;
        }
        if (isset($attrs['border'])) {
            $borders = BorderSet::all(BorderParser::parse($attrs['border'].'px solid #000'));
        }

        foreach ($properties as $prop => $value) {
            switch ($prop) {
                case 'width':
                    self::applyWidth($value, $widthTwips, $widthPercent);

                    break;
                case 'table-layout':
                    $fixed = strtolower(trim($value)) === 'fixed';

                    break;
                case 'border':
                    $borders = BorderSet::all(BorderParser::parse($value));

                    break;
            }
        }

        return new TableStyle(
            widthTwips: $widthTwips,
            widthPercent: $widthPercent,
            fixedLayout: $fixed,
            borders: $borders,
            alignment: $align,
            cellMarginTopTwips: $base->cellMarginTopTwips,
            cellMarginRightTwips: $base->cellMarginRightTwips,
            cellMarginBottomTwips: $base->cellMarginBottomTwips,
            cellMarginLeftTwips: $base->cellMarginLeftTwips,
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

    private static function mapAlign(string $value): ?Alignment
    {
        return match (strtolower(trim($value))) {
            'left', 'start' => Alignment::Start,
            'center' => Alignment::Center,
            'right', 'end' => Alignment::End,
            default => null,
        };
    }
}
