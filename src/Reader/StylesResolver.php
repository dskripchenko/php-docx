<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Reader;

use Dskripchenko\PhpDocx\Style\Alignment;
use Dskripchenko\PhpDocx\Style\BorderSet;
use Dskripchenko\PhpDocx\Style\ParagraphStyle;
use Dskripchenko\PhpDocx\Style\RunStyle;

/**
 * The resolution of the OOXML style cascade:
 *
 *   docDefaults.rPr/pPr
 *     ↓
 *   the named paragraph style (with its basedOn chain)
 *     ↓
 *   the linked character style (when the run has a <w:rStyle>)
 *     ↓
 *   the direct <w:rPr>/<w:pPr> on the element
 *     ↓
 *   the effective RunStyle/ParagraphStyle
 *
 * The algorithm:
 *  1. parse styles.xml into a map of
 *     "styleId → { type, basedOn, pPr-partial, rPr-partial }";
 *  2. resolve the basedOn chain lazily (memoizing the result);
 *  3. for every `<w:p>`/`<w:r>` build the cascade and convert the partial array
 *     into a final RunStyle/ParagraphStyle.
 *
 * The headings are a special case: a style named `Heading1..6` gives the
 * headingLevel.
 */
final class StylesResolver
{
    /** @var array<string, mixed> docDefaults rPr partial */
    private array $docDefaultsRPr = [];

    /** @var array<string, mixed> docDefaults pPr partial */
    private array $docDefaultsPPr = [];

    /**
     * The style marked `w:default="1"` — the one Word applies to the paragraphs
     * without an explicit `pStyle`.
     *
     * It cannot be skipped: documents routinely state one thing in docDefaults
     * and another in the default style, and the latter must win. In the
     * reference policy the docDefaults promised 8pt after every paragraph while
     * the default style reset them to zero; without this layer each of the 246
     * paragraphs got an extra 8pt and the document swelled from five pages to
     * seven.
     */
    private ?string $defaultParagraphStyleId = null;

    /** The same for the runs: `w:type="character" w:default="1"`. */
    private ?string $defaultCharacterStyleId = null;

    /** @var array<string, array{type: string, basedOn: ?string, pPr: array<string, mixed>, rPr: array<string, mixed>}> */
    private array $stylesById = [];

    /** @var array<string, array{pPr: array<string, mixed>, rPr: array<string, mixed>}>  the cache of the after-basedOn merge */
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
     * Resolves the effective paragraph style and run style of a `<w:p>` element.
     *
     * Returns [paragraphStyle, runStyle, headingLevel, numId, ilvl].
     *
     * @return array{0: ParagraphStyle, 1: RunStyle, 2: ?int, 3: ?int, 4: ?int}
     */
    public function effectiveStylesForParagraph(\DOMElement $paragraph): array
    {
        $pPrEl = OoxmlNs::firstChild($paragraph, OoxmlNs::W, 'pPr');
        $direct = $this->parser->parseParagraphProperties($pPrEl);

        // The pStyle decides the heading level AND adds one more cascade layer.
        $styleId = $direct['pStyleId'] ?? null;
        $headingLevel = $this->styleIdToHeadingLevel($styleId);

        $namedPPr = [];
        $namedRPr = [];
        if ($styleId !== null && isset($this->stylesById[$styleId])) {
            $resolved = $this->resolveStyleChain($styleId);
            $namedPPr = $resolved['pPr'];
            $namedRPr = $resolved['rPr'];
        }

        // A pPr may hold a <w:rPr> of its own — but that is the format of the
        // PARAGRAPH MARK (the ¶ character), not of the text inside it. Word
        // applies it to whatever is typed at the end of the paragraph and does
        // not touch the existing runs.
        //
        // It used to be mixed into the base run style, and any property from
        // there leaked onto the whole paragraph: in the reference policy the
        // paragraph mark was marked bold and the entire document printed bold —
        // the lines came out 16% wider and the layout diverged from the original
        // everywhere.
        $pPrRPr = [];

        // The order of the cascade per ECMA-376: docDefaults → the default style
        // → the named style → the direct formatting. Every next layer overrides
        // the previous one.
        $defaultPPr = [];
        $defaultRPr = [];
        if ($this->defaultParagraphStyleId !== null) {
            $resolvedDefault = $this->resolveStyleChain($this->defaultParagraphStyleId);
            $defaultPPr = $resolvedDefault['pPr'];
            $defaultRPr = $resolvedDefault['rPr'];
        }
        if ($this->defaultCharacterStyleId !== null) {
            $defaultRPr = array_merge($defaultRPr, $this->resolveStyleChain($this->defaultCharacterStyleId)['rPr']);
        }

        $finalPPr = array_merge($this->docDefaultsPPr, $defaultPPr, $namedPPr, $direct);
        $finalRPr = array_merge($this->docDefaultsRPr, $defaultRPr, $namedRPr, $pPrRPr);

        return [
            $this->arrayToParagraphStyle($finalPPr),
            $this->arrayToRunStyle($finalRPr),
            $headingLevel,
            $direct['numId'] ?? null,
            $direct['ilvl'] ?? null,
        ];
    }

    /**
     * The effective RunStyle of a `<w:r>`, taking into account the direct rPr
     * plus the parent paragraph's default rPr (passed in through $baseRPr).
     */
    public function effectiveStylesForRun(\DOMElement $run, RunStyle $baseRunStyle): RunStyle
    {
        $rPrEl = OoxmlNs::firstChild($run, OoxmlNs::W, 'rPr');
        if ($rPrEl === null) {
            return $baseRunStyle;
        }
        $direct = $this->parser->parseRunProperties($rPrEl);

        // A linked character style (<w:rStyle w:val="StyleId"/>) adds one more
        // resolved rPr layer.
        $charStyleId = null;
        $rStyleEl = OoxmlNs::firstChild($rPrEl, OoxmlNs::W, 'rStyle');
        if ($rStyleEl !== null) {
            $charStyleId = OoxmlNs::wVal($rStyleEl);
        }
        $namedRPr = [];
        if ($charStyleId !== null && isset($this->stylesById[$charStyleId])) {
            $namedRPr = $this->resolveStyleChain($charStyleId)['rPr'];
        }

        // baseRunStyle already includes the docDefaults, the named paragraph
        // style's rPr and the pPr.rPr. On top of it go namedRPr (from the
        // character style) and then the direct one.
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

            if ($styleEl->getAttributeNS(OoxmlNs::W, 'default') === '1') {
                if ($type === 'paragraph') {
                    $this->defaultParagraphStyleId ??= $id;
                } elseif ($type === 'character') {
                    $this->defaultCharacterStyleId ??= $id;
                }
            }

            $this->stylesById[$id] = [
                'type' => $type,
                'basedOn' => $basedOn,
                'pPr' => $this->parser->parseParagraphProperties($pPrEl),
                'rPr' => $this->parser->parseRunProperties($rPrEl),
            ];
        }
    }

    /**
     * Resolves the basedOn chain of a named style into an assembled partial
     * state. It is memoized through $resolvedCache, and the cycles are detected
     * with a visited set.
     *
     * @return array{pPr: array<string, mixed>, rPr: array<string, mixed>}
     */
    private function resolveStyleChain(string $styleId, array $visited = []): array
    {
        if (isset($this->resolvedCache[$styleId])) {
            return $this->resolvedCache[$styleId];
        }
        if (in_array($styleId, $visited, true)) {
            // A cycle — we return an empty one.
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
            allCaps: (bool) ($arr['allCaps'] ?? false),
            smallCaps: (bool) ($arr['smallCaps'] ?? false),
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
            lineSpacingRule: isset($arr['lineSpacingRule']) ? (string) $arr['lineSpacingRule'] : null,
            pageBreakAfter: false,
            keepWithNext: (bool) ($arr['keepWithNext'] ?? false),
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
        $out['allCaps'] = $style->allCaps;
        $out['smallCaps'] = $style->smallCaps;

        return $out;
    }
}
