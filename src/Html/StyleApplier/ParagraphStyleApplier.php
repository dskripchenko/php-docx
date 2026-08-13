<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Html\StyleApplier;

use Dskripchenko\PhpDocx\Html\ColorParser;
use Dskripchenko\PhpDocx\Html\LengthParser;
use Dskripchenko\PhpDocx\Style\Alignment;
use Dskripchenko\PhpDocx\Style\ParagraphStyle;

/**
 * Applies CSS to a ParagraphStyle (`<w:pPr>`).
 *
 * Supported:
 *  - text-align (start/center/end/justify)
 *  - margin / margin-top / margin-bottom → spaceBefore/After
 *  - text-indent → indentFirstLine
 *  - margin-left/right → indentLeft/Right
 *  - line-height (a multiplier or an absolute value)
 *  - page-break-after: always
 *  - background-color / background → the paragraph fill (`<w:shd>`)
 */
final class ParagraphStyleApplier
{
    /**
     * @param  array<string, string>  $properties
     */
    public static function apply(ParagraphStyle $base, array $properties): ParagraphStyle
    {
        $alignment = $base->alignment;
        $spaceBefore = $base->spaceBeforeTwips;
        $spaceAfter = $base->spaceAfterTwips;
        $indentLeft = $base->indentLeftTwips;
        $indentRight = $base->indentRightTwips;
        $indentFirst = $base->indentFirstLineTwips;
        $lineSpacing = $base->lineSpacingTwips;
        $pageBreakAfter = $base->pageBreakAfter;
        $shading = $base->shadingColor;

        foreach ($properties as $prop => $value) {
            switch ($prop) {
                case 'text-align':
                    $alignment = self::mapAlignment($value) ?? $alignment;

                    break;

                case 'margin':
                    [$top, $right, $bottom, $left] = self::expandFourSides($value);
                    if ($top !== null) {
                        $spaceBefore = $top;
                    }
                    if ($bottom !== null) {
                        $spaceAfter = $bottom;
                    }
                    if ($left !== null) {
                        $indentLeft = $left;
                    }
                    if ($right !== null) {
                        $indentRight = $right;
                    }

                    break;

                case 'margin-top':
                    $t = LengthParser::parseTwips($value);
                    if ($t !== null) {
                        $spaceBefore = $t;
                    }

                    break;
                case 'margin-bottom':
                    $t = LengthParser::parseTwips($value);
                    if ($t !== null) {
                        $spaceAfter = $t;
                    }

                    break;
                case 'margin-left':
                    $t = LengthParser::parseTwips($value);
                    if ($t !== null) {
                        $indentLeft = $t;
                    }

                    break;
                case 'margin-right':
                    $t = LengthParser::parseTwips($value);
                    if ($t !== null) {
                        $indentRight = $t;
                    }

                    break;

                case 'text-indent':
                    $t = LengthParser::parseTwips($value);
                    if ($t !== null) {
                        $indentFirst = $t;
                    }

                    break;

                case 'line-height':
                    $lineSpacing = self::parseLineHeight($value) ?? $lineSpacing;

                    break;

                case 'background-color':
                case 'background':
                    // The shorthand `background: #fff` without an image is a fill colour.
                    $hex = ColorParser::parse($value);
                    if ($hex !== null) {
                        $shading = $hex;
                    }

                    break;

                case 'page-break-after':
                case 'break-after':
                    $pageBreakAfter = in_array(strtolower(trim($value)), ['always', 'page'], true);

                    break;
            }
        }

        return new ParagraphStyle(
            alignment: $alignment,
            spaceBeforeTwips: $spaceBefore,
            spaceAfterTwips: $spaceAfter,
            indentLeftTwips: $indentLeft,
            indentRightTwips: $indentRight,
            indentFirstLineTwips: $indentFirst,
            lineSpacingTwips: $lineSpacing,
            pageBreakAfter: $pageBreakAfter,
            borders: $base->borders,
            shadingColor: $shading,
        );
    }

    private static function mapAlignment(string $value): ?Alignment
    {
        return match (strtolower(trim($value))) {
            'left', 'start' => Alignment::Start,
            'center' => Alignment::Center,
            'right', 'end' => Alignment::End,
            'justify' => Alignment::Justify,
            default => null,
        };
    }

    /**
     * The CSS shorthand `margin: top right bottom left` → 4 values in twips.
     *
     * @return array{?int, ?int, ?int, ?int}
     */
    private static function expandFourSides(string $value): array
    {
        $parts = preg_split('/\s+/', trim($value)) ?: [];
        $twips = array_map(fn (string $p): ?int => LengthParser::parseTwips($p), $parts);

        return match (count($twips)) {
            1 => [$twips[0], $twips[0], $twips[0], $twips[0]],
            2 => [$twips[0], $twips[1], $twips[0], $twips[1]],
            3 => [$twips[0], $twips[1], $twips[2], $twips[1]],
            4 => [$twips[0], $twips[1], $twips[2], $twips[3]],
            default => [null, null, null, null],
        };
    }

    /**
     * CSS line-height:
     *   - a unitless number ("1.5") → a multiplier
     *   - one with units ("18pt") → an absolute value
     *   - a percentage ("150%") → a multiplier × 1%
     * Returns the value in twips (for
     * `<w:spacing w:line="..." w:lineRule="exact"/>`).
     */
    private static function parseLineHeight(string $value): ?int
    {
        $value = trim($value);
        if ($value === '' || strtolower($value) === 'normal') {
            return null;
        }
        if (str_ends_with($value, '%')) {
            $pct = (float) rtrim($value, '%');

            return (int) round($pct / 100 * 240); // 240 twips = 12pt single-line
        }
        if (is_numeric($value)) {
            return (int) round(((float) $value) * 240);
        }

        return LengthParser::parseTwips($value);
    }
}
