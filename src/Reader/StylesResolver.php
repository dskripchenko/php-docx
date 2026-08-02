<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Reader;

use Dskripchenko\PhpDocx\Style\Alignment;
use Dskripchenko\PhpDocx\Style\BorderSet;
use Dskripchenko\PhpDocx\Style\ParagraphStyle;
use Dskripchenko\PhpDocx\Style\RunStyle;

/**
 * Резолв OOXML style cascade:
 *
 *   docDefaults.rPr/pPr
 *     ↓
 *   Named paragraph style (с basedOn chain)
 *     ↓
 *   Linked character style (если есть <w:rStyle> в run)
 *     ↓
 *   Direct <w:rPr>/<w:pPr> on element
 *     ↓
 *   Effective RunStyle/ParagraphStyle
 *
 * Алгоритм:
 *  1. Парсим styles.xml на map "styleId → { type, basedOn, pPr-partial, rPr-partial }"
 *  2. Лениво резолвим basedOn chain (memoize'им результат)
 *  3. Для каждого `<w:p>`/<w:r>` строим cascade и конвертируем
 *     partial-array → final RunStyle/ParagraphStyle.
 *
 * Headings — особый случай: имя стиля `Heading1..6` → headingLevel.
 */
final class StylesResolver
{
    /** @var array<string, mixed> docDefaults rPr partial */
    private array $docDefaultsRPr = [];

    /** @var array<string, mixed> docDefaults pPr partial */
    private array $docDefaultsPPr = [];

    /** @var array<string, array{type: string, basedOn: ?string, pPr: array<string, mixed>, rPr: array<string, mixed>}> */
    private array $stylesById = [];

    /** @var array<string, array{pPr: array<string, mixed>, rPr: array<string, mixed>}>  кеш after-basedOn merge */
    private array $resolvedCache = [];

    private OoxmlPropertyParser $parser;

    public function __construct(
        ?\DOMDocument $stylesXml = null,
        private readonly ThemeResolver $theme = new ThemeResolver,
    ) {
        $this->parser = new OoxmlPropertyParser($this->theme);
        if ($stylesXml !== null) {
            $this->loadStyles($stylesXml);
        }
    }

    public function parser(): OoxmlPropertyParser
    {
        return $this->parser;
    }

    /**
     * Резолв effective paragraph-style + run-style для `<w:p>`-элемента.
     *
     * Возвращает [paragraphStyle, runStyle, headingLevel, numId, ilvl].
     *
     * @return array{0: ParagraphStyle, 1: RunStyle, 2: ?int, 3: ?int, 4: ?int}
     */
    public function effectiveStylesForParagraph(\DOMElement $paragraph): array
    {
        $pPrEl = OoxmlNs::firstChild($paragraph, OoxmlNs::W, 'pPr');
        $direct = $this->parser->parseParagraphProperties($pPrEl);

        // pStyle определяет heading-level И добавляет ещё один cascade-layer.
        $styleId = $direct['pStyleId'] ?? null;
        $headingLevel = $this->styleIdToHeadingLevel($styleId);

        $namedPPr = [];
        $namedRPr = [];
        if ($styleId !== null && isset($this->stylesById[$styleId])) {
            $resolved = $this->resolveStyleChain($styleId);
            $namedPPr = $resolved['pPr'];
            $namedRPr = $resolved['rPr'];
        }

        // Также внутри pPr может быть свой <w:rPr> — это default-rPr для runs
        // которые не имеют собственного rPr. Применяется поверх namedRPr.
        $pPrRPrEl = $pPrEl !== null ? OoxmlNs::firstChild($pPrEl, OoxmlNs::W, 'rPr') : null;
        $pPrRPr = $this->parser->parseRunProperties($pPrRPrEl);

        $finalPPr = array_merge($this->docDefaultsPPr, $namedPPr, $direct);
        $finalRPr = array_merge($this->docDefaultsRPr, $namedRPr, $pPrRPr);

        return [
            $this->arrayToParagraphStyle($finalPPr),
            $this->arrayToRunStyle($finalRPr),
            $headingLevel,
            $direct['numId'] ?? null,
            $direct['ilvl'] ?? null,
        ];
    }

    /**
     * Effective RunStyle для `<w:r>` с учётом direct rPr + parent paragraph's
     * default rPr (передаём через $baseRPr).
     */
    public function effectiveStylesForRun(\DOMElement $run, RunStyle $baseRunStyle): RunStyle
    {
        $rPrEl = OoxmlNs::firstChild($run, OoxmlNs::W, 'rPr');
        if ($rPrEl === null) {
            return $baseRunStyle;
        }
        $direct = $this->parser->parseRunProperties($rPrEl);

        // Linked character style (<w:rStyle w:val="StyleId"/>) — добавляет
        // ещё одну resolved-rPr layer.
        $charStyleId = null;
        $rStyleEl = OoxmlNs::firstChild($rPrEl, OoxmlNs::W, 'rStyle');
        if ($rStyleEl !== null) {
            $charStyleId = OoxmlNs::wVal($rStyleEl);
        }
        $namedRPr = [];
        if ($charStyleId !== null && isset($this->stylesById[$charStyleId])) {
            $namedRPr = $this->resolveStyleChain($charStyleId)['rPr'];
        }

        // baseRunStyle уже включает docDefaults + named paragraph-style rPr +
        // pPr.rPr. На него layer'ом namedRPr (от character style) + direct.
        $base = $this->runStyleToArray($baseRunStyle);
        $merged = array_merge($base, $namedRPr, $direct);

        return $this->arrayToRunStyle($merged);
    }

    private function styleIdToHeadingLevel(?string $styleId): ?int
    {
        if ($styleId === null) {
            return null;
        }
        if (preg_match('/^Heading(\d)$/i', $styleId, $m) === 1) {
            $level = (int) $m[1];
            if ($level >= 1 && $level <= 6) {
                return $level;
            }
        }

        return null;
    }

    private function loadStyles(\DOMDocument $doc): void
    {
        $root = $doc->documentElement;
        if ($root === null) {
            return;
        }

        // <w:docDefaults>
        $docDefaults = OoxmlNs::firstChild($root, OoxmlNs::W, 'docDefaults');
        if ($docDefaults !== null) {
            $rPrDefault = OoxmlNs::firstChild($docDefaults, OoxmlNs::W, 'rPrDefault');
            if ($rPrDefault !== null) {
                $rPr = OoxmlNs::firstChild($rPrDefault, OoxmlNs::W, 'rPr');
                $this->docDefaultsRPr = $this->parser->parseRunProperties($rPr);
            }
            $pPrDefault = OoxmlNs::firstChild($docDefaults, OoxmlNs::W, 'pPrDefault');
            if ($pPrDefault !== null) {
                $pPr = OoxmlNs::firstChild($pPrDefault, OoxmlNs::W, 'pPr');
                $this->docDefaultsPPr = $this->parser->parseParagraphProperties($pPr);
            }
        }

        // <w:style>
        foreach (OoxmlNs::children($root, OoxmlNs::W, 'style') as $styleEl) {
            $type = $styleEl->getAttributeNS(OoxmlNs::W, 'type');
            $id = $styleEl->getAttributeNS(OoxmlNs::W, 'styleId');
            if ($id === '') {
                continue;
            }
            $basedOnEl = OoxmlNs::firstChild($styleEl, OoxmlNs::W, 'basedOn');
            $basedOn = $basedOnEl !== null ? OoxmlNs::wVal($basedOnEl) : null;
            $pPrEl = OoxmlNs::firstChild($styleEl, OoxmlNs::W, 'pPr');
            $rPrEl = OoxmlNs::firstChild($styleEl, OoxmlNs::W, 'rPr');

            $this->stylesById[$id] = [
                'type' => $type,
                'basedOn' => $basedOn,
                'pPr' => $this->parser->parseParagraphProperties($pPrEl),
                'rPr' => $this->parser->parseRunProperties($rPrEl),
            ];
        }
    }

    /**
     * Резолв basedOn chain для named style → собранный partial state.
     * Memoize'им через $resolvedCache, циклы детектируем visited-set'ом.
     *
     * @return array{pPr: array<string, mixed>, rPr: array<string, mixed>}
     */
    private function resolveStyleChain(string $styleId, array $visited = []): array
    {
        if (isset($this->resolvedCache[$styleId])) {
            return $this->resolvedCache[$styleId];
        }
        if (in_array($styleId, $visited, true)) {
            // Цикл — возвращаем пустое.
            return ['pPr' => [], 'rPr' => []];
        }
        $entry = $this->stylesById[$styleId] ?? null;
        if ($entry === null) {
            return ['pPr' => [], 'rPr' => []];
        }
        $visited[] = $styleId;

        $base = $entry['basedOn'] !== null
            ? $this->resolveStyleChain($entry['basedOn'], $visited)
            : ['pPr' => [], 'rPr' => []];

        $merged = [
            'pPr' => array_merge($base['pPr'], $entry['pPr']),
            'rPr' => array_merge($base['rPr'], $entry['rPr']),
        ];
        $this->resolvedCache[$styleId] = $merged;

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $arr
     */
    private function arrayToRunStyle(array $arr): RunStyle
    {
        return new RunStyle(
            sizeHalfPoints: isset($arr['sizeHalfPoints']) ? (int) $arr['sizeHalfPoints'] : null,
            color: $arr['color'] ?? null,
            backgroundColor: $arr['backgroundColor'] ?? null,
            fontFamily: $arr['fontFamily'] ?? null,
            bold: (bool) ($arr['bold'] ?? false),
            italic: (bool) ($arr['italic'] ?? false),
            underline: (bool) ($arr['underline'] ?? false),
            strikethrough: (bool) ($arr['strikethrough'] ?? false),
            superscript: (bool) ($arr['superscript'] ?? false),
            subscript: (bool) ($arr['subscript'] ?? false),
            highlight: $arr['highlight'] ?? null,
            letterSpacingTwips: isset($arr['letterSpacingTwips']) ? (int) $arr['letterSpacingTwips'] : null,
        );
    }

    /**
     * @param  array<string, mixed>  $arr
     */
    private function arrayToParagraphStyle(array $arr): ParagraphStyle
    {
        $borders = $arr['borders'] ?? null;

        return new ParagraphStyle(
            alignment: $arr['alignment'] ?? Alignment::Start,
            spaceBeforeTwips: (int) ($arr['spaceBeforeTwips'] ?? 0),
            spaceAfterTwips: (int) ($arr['spaceAfterTwips'] ?? 0),
            indentLeftTwips: (int) ($arr['indentLeftTwips'] ?? 0),
            indentRightTwips: (int) ($arr['indentRightTwips'] ?? 0),
            indentFirstLineTwips: (int) ($arr['indentFirstLineTwips'] ?? 0),
            lineSpacingTwips: isset($arr['lineSpacingTwips']) ? (int) $arr['lineSpacingTwips'] : null,
            pageBreakAfter: false,
            borders: $borders instanceof BorderSet ? $borders : null,
            shadingColor: $arr['shadingColor'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function runStyleToArray(RunStyle $style): array
    {
        $out = [];
        if ($style->sizeHalfPoints !== null) {
            $out['sizeHalfPoints'] = $style->sizeHalfPoints;
        }
        if ($style->color !== null) {
            $out['color'] = $style->color;
        }
        if ($style->backgroundColor !== null) {
            $out['backgroundColor'] = $style->backgroundColor;
        }
        if ($style->fontFamily !== null) {
            $out['fontFamily'] = $style->fontFamily;
        }
        if ($style->highlight !== null) {
            $out['highlight'] = $style->highlight;
        }
        if ($style->letterSpacingTwips !== null) {
            $out['letterSpacingTwips'] = $style->letterSpacingTwips;
        }
        $out['bold'] = $style->bold;
        $out['italic'] = $style->italic;
        $out['underline'] = $style->underline;
        $out['strikethrough'] = $style->strikethrough;
        $out['superscript'] = $style->superscript;
        $out['subscript'] = $style->subscript;

        return $out;
    }
}
