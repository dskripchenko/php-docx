<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Reader;

use Dskripchenko\PhpDocx\Style\Alignment;
use Dskripchenko\PhpDocx\Style\Border;
use Dskripchenko\PhpDocx\Style\BorderSet;
use Dskripchenko\PhpDocx\Style\BorderStyle;

/**
 * Парсер `<w:rPr>` и `<w:pPr>` блоков в partial-style arrays.
 *
 * Возвращает `array<string, mixed>` где ключи соответствуют полям
 * RunStyle/ParagraphStyle, и присутствуют только те ключи, которые
 * объявлены в текущем layer'е. Это позволяет cascade-merge простым
 * array_merge.
 *
 * Theme-references (`w:themeColor="accent1"`) резолвятся через ThemeResolver.
 */
final class OoxmlPropertyParser
{
    public function __construct(
        private readonly ThemeResolver $theme = new ThemeResolver,
    ) {}

    /**
     * Парсит `<w:rPr>` (или null) → partial run-style array.
     *
     * Ключи (если присутствуют): sizeHalfPoints, color, fontFamily,
     * bold, italic, underline, strikethrough, superscript, subscript,
     * highlight, backgroundColor, letterSpacingTwips.
     *
     * @return array<string, mixed>
     */
    public function parseRunProperties(?\DOMElement $rPr): array
    {
        if ($rPr === null) {
            return [];
        }
        $out = [];

        foreach ($rPr->childNodes as $node) {
            if (! $node instanceof \DOMElement || $node->namespaceURI !== OoxmlNs::W) {
                continue;
            }
            match ($node->localName) {
                // `bCs`/`iCs`/`szCs` описывают начертание и размер для СЛОЖНЫХ
                // письменностей (арабской, ивритской, индийских). К латинице
                // и кириллице они не применяются: Word такой текст рисует
                // обычным. Пока их принимали за обычные `b`/`i`/`sz`, документ
                // с `<w:bCs/>` на каждом руне печатался жирным целиком —
                // именно так выглядело заявление страхователя.
                'b' => $out['bold'] = OoxmlNs::boolToggle($node),
                'i' => $out['italic'] = OoxmlNs::boolToggle($node),
                'u' => $out['underline'] = $this->parseUnderline($node),
                'caps' => $out['allCaps'] = OoxmlNs::boolToggle($node),
                'smallCaps' => $out['smallCaps'] = OoxmlNs::boolToggle($node),
                'strike', 'dstrike' => $out['strikethrough'] = OoxmlNs::boolToggle($node),
                'vertAlign' => $this->parseVertAlign($node, $out),
                'color' => $out['color'] = $this->parseColor($node),
                'sz' => $out['sizeHalfPoints'] = $this->parseInt($node),
                'rFonts' => $out['fontFamily'] = $this->parseFontFamily($node),
                'highlight' => $out['highlight'] = OoxmlNs::wVal($node),
                'shd' => $out['backgroundColor'] = $this->parseShdFill($node),
                // Тёзка pPr/spacing (межстрочный интервал), но здесь это
                // разрядка символов в twips.
                'spacing' => $out['letterSpacingTwips'] = $this->parseInt($node),
                default => null,
            };
        }
        // Удаляем null-значения (плохо распарсенные).
        return array_filter($out, fn ($v) => $v !== null);
    }

    /**
     * Парсит `<w:pPr>` → partial paragraph-style array.
     *
     * Ключи: alignment, spaceBeforeTwips, spaceAfterTwips,
     * indentLeftTwips, indentRightTwips, indentFirstLineTwips,
     * lineSpacingTwips, pageBreakAfter, borders, shadingColor,
     * pStyleId, numId, ilvl.
     *
     * @return array<string, mixed>
     */
    public function parseParagraphProperties(?\DOMElement $pPr): array
    {
        if ($pPr === null) {
            return [];
        }
        $out = [];

        foreach ($pPr->childNodes as $node) {
            if (! $node instanceof \DOMElement || $node->namespaceURI !== OoxmlNs::W) {
                continue;
            }
            match ($node->localName) {
                'pStyle' => $out['pStyleId'] = OoxmlNs::wVal($node),
                'jc' => $out['alignment'] = $this->parseAlignment(OoxmlNs::wVal($node)),
                'ind' => $this->parseIndent($node, $out),
                'spacing' => $this->parseSpacing($node, $out),
                'pageBreakBefore' => $out['pageBreakBefore'] = OoxmlNs::boolToggle($node),
                'keepNext' => $out['keepWithNext'] = OoxmlNs::boolToggle($node),
                'pBdr' => $out['borders'] = $this->parseBorders($node),
                'shd' => $out['shadingColor'] = $this->parseShdFill($node),
                'numPr' => $this->parseNumPr($node, $out),
                default => null,
            };
        }

        return array_filter($out, fn ($v) => $v !== null);
    }

    private function parseUnderline(\DOMElement $u): bool
    {
        // <w:u w:val="single"/> = underline on; <w:u w:val="none"/> = off.
        $val = OoxmlNs::wVal($u);
        if ($val === null || $val === '') {
            return true;
        }

        return strtolower($val) !== 'none';
    }

    /**
     * @param  array<string, mixed>  $out
     */
    private function parseVertAlign(\DOMElement $node, array &$out): void
    {
        $val = OoxmlNs::wVal($node);
        if ($val === 'superscript') {
            $out['superscript'] = true;
            $out['subscript'] = false;
        } elseif ($val === 'subscript') {
            $out['subscript'] = true;
            $out['superscript'] = false;
        } else {
            $out['superscript'] = false;
            $out['subscript'] = false;
        }
    }

    private function parseColor(\DOMElement $node): ?string
    {
        $val = OoxmlNs::wVal($node);
        if ($val !== null && $val !== 'auto' && preg_match('/^[0-9A-Fa-f]{6}$/', $val) === 1) {
            return strtolower($val);
        }
        // Theme color reference.
        if ($node->hasAttributeNS(OoxmlNs::W, 'themeColor')) {
            $themeKey = $node->getAttributeNS(OoxmlNs::W, 'themeColor');
            $resolved = $this->theme->resolveColor($themeKey);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }

    private function parseInt(\DOMElement $node): ?int
    {
        $val = OoxmlNs::wVal($node);
        if ($val === null || ! ctype_digit(ltrim($val, '-'))) {
            return null;
        }

        return (int) $val;
    }

    private function parseFontFamily(\DOMElement $rFonts): ?string
    {
        // Order priority: ascii > hAnsi > cs > eastAsia.
        foreach (['ascii', 'hAnsi', 'cs', 'eastAsia'] as $attr) {
            if ($rFonts->hasAttributeNS(OoxmlNs::W, $attr)) {
                $val = $rFonts->getAttributeNS(OoxmlNs::W, $attr);
                if ($val !== '') {
                    return $val;
                }
            }
        }
        // Theme variants.
        foreach (['asciiTheme', 'hAnsiTheme', 'csTheme', 'eastAsiaTheme'] as $attr) {
            if ($rFonts->hasAttributeNS(OoxmlNs::W, $attr)) {
                $themeKey = $rFonts->getAttributeNS(OoxmlNs::W, $attr);
                $resolved = $this->theme->resolveFont($themeKey);
                if ($resolved !== null) {
                    return $resolved;
                }
            }
        }

        return null;
    }

    private function parseShdFill(\DOMElement $shd): ?string
    {
        if (! $shd->hasAttributeNS(OoxmlNs::W, 'fill')) {
            return null;
        }
        $fill = $shd->getAttributeNS(OoxmlNs::W, 'fill');
        if ($fill === 'auto' || $fill === '') {
            return null;
        }

        return strtolower($fill);
    }

    private function parseAlignment(?string $val): Alignment
    {
        return match ($val) {
            'center' => Alignment::Center,
            'right', 'end' => Alignment::End,
            'both' => Alignment::Justify,
            'distribute' => Alignment::Distribute,
            default => Alignment::Start,
        };
    }

    /**
     * @param  array<string, mixed>  $out
     */
    private function parseIndent(\DOMElement $ind, array &$out): void
    {
        foreach (['left' => 'indentLeftTwips', 'start' => 'indentLeftTwips',
            'right' => 'indentRightTwips', 'end' => 'indentRightTwips',
            'firstLine' => 'indentFirstLineTwips'] as $attr => $key) {
            if ($ind->hasAttributeNS(OoxmlNs::W, $attr)) {
                $val = $ind->getAttributeNS(OoxmlNs::W, $attr);
                if (ctype_digit($val)) {
                    $out[$key] = (int) $val;
                }
            }
        }
        // Hanging indent — Word представляет как firstLine отрицательный смещение.
        if ($ind->hasAttributeNS(OoxmlNs::W, 'hanging')) {
            $hanging = (int) $ind->getAttributeNS(OoxmlNs::W, 'hanging');
            if ($hanging > 0) {
                $out['indentFirstLineTwips'] = -$hanging;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $out
     */
    private function parseSpacing(\DOMElement $sp, array &$out): void
    {
        if ($sp->hasAttributeNS(OoxmlNs::W, 'before')) {
            $out['spaceBeforeTwips'] = (int) $sp->getAttributeNS(OoxmlNs::W, 'before');
        }
        if ($sp->hasAttributeNS(OoxmlNs::W, 'after')) {
            $out['spaceAfterTwips'] = (int) $sp->getAttributeNS(OoxmlNs::W, 'after');
        }
        if ($sp->hasAttributeNS(OoxmlNs::W, 'line')) {
            $line = (int) $sp->getAttributeNS(OoxmlNs::W, 'line');
            if ($line > 0) {
                $out['lineSpacingTwips'] = $line;
                // Без правила число ничего не значит: при `auto` это доля от
                // одинарного интервала (240 = один), при `exact`/`atLeast` —
                // высота строки в двадцатых долях пункта.
                $rule = $sp->getAttributeNS(OoxmlNs::W, 'lineRule');
                $out['lineSpacingRule'] = $rule !== '' ? $rule : 'auto';
            }
        }
    }

    /**
     * Public для использования из TableReader (tblBorders/tcBorders имеют
     * тот же sub-schema что и pBdr — top/left/bottom/right + insideH/V).
     */
    public function parseBorders(\DOMElement $pBdr): BorderSet
    {
        $sides = [
            'top' => null, 'left' => null, 'bottom' => null, 'right' => null,
            'insideH' => null, 'insideV' => null,
        ];
        foreach ($pBdr->childNodes as $child) {
            if (! $child instanceof \DOMElement || $child->namespaceURI !== OoxmlNs::W) {
                continue;
            }
            $name = $child->localName;
            if (! array_key_exists($name, $sides)) {
                continue;
            }
            $sides[$name] = $this->parseBorder($child);
        }

        return new BorderSet(
            top: $sides['top'],
            left: $sides['left'],
            bottom: $sides['bottom'],
            right: $sides['right'],
            insideH: $sides['insideH'],
            insideV: $sides['insideV'],
        );
    }

    private function parseBorder(\DOMElement $b): Border
    {
        $val = OoxmlNs::wVal($b) ?? 'single';
        $style = match (strtolower($val)) {
            'none', 'nil' => BorderStyle::None,
            'dashed' => BorderStyle::Dashed,
            'dotted' => BorderStyle::Dotted,
            'double' => BorderStyle::Double,
            default => BorderStyle::Single,
        };
        $size = $b->hasAttributeNS(OoxmlNs::W, 'sz')
            ? (int) $b->getAttributeNS(OoxmlNs::W, 'sz')
            : 4;
        $color = $b->hasAttributeNS(OoxmlNs::W, 'color')
            ? $b->getAttributeNS(OoxmlNs::W, 'color')
            : '000000';
        if ($color === 'auto' || ! preg_match('/^[0-9A-Fa-f]{6}$/', $color)) {
            $color = '000000';
        }

        return new Border(style: $style, sizeEighthsOfPoint: max(1, $size), color: strtolower($color));
    }

    /**
     * @param  array<string, mixed>  $out
     */
    private function parseNumPr(\DOMElement $numPr, array &$out): void
    {
        $ilvl = OoxmlNs::firstChild($numPr, OoxmlNs::W, 'ilvl');
        $numId = OoxmlNs::firstChild($numPr, OoxmlNs::W, 'numId');
        if ($numId !== null) {
            $val = OoxmlNs::wVal($numId);
            if ($val !== null && ctype_digit($val)) {
                $out['numId'] = (int) $val;
            }
        }
        if ($ilvl !== null) {
            $val = OoxmlNs::wVal($ilvl);
            if ($val !== null && ctype_digit($val)) {
                $out['ilvl'] = (int) $val;
            }
        }
    }
}
