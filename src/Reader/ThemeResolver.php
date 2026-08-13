<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Reader;

/**
 * Resolves theme colour and font references.
 *
 * In OOXML attributes such as `<w:color w:themeColor="accent1"/>` point at
 * `theme1.xml` `<a:clrScheme>/<a:accent1>`, which holds either
 * `<a:srgbClr val="14B8A6"/>` or `<a:sysClr val="windowText" lastClr="000000"/>`.
 *
 * Fonts work the same way: `w:asciiTheme="majorAscii"` →
 * `<a:majorFont>/<a:latin typeface="…"/>`.
 *
 * When theme1.xml is missing or the reference does not resolve, null is
 * returned.
 */
final class ThemeResolver
{
    /** @var array<string, string> Map themeColorKey → hex (without the #) */
    private array $colorMap = [];

    /** @var array<string, string> Map themeFontKey → typeface name */
    private array $fontMap = [];

    public function __construct(?\DOMDocument $themeXml = null)
    {
        if ($themeXml !== null) {
            $this->loadThemeXml($themeXml);
        }
    }

    /**
     * Resolves `<w:color w:themeColor="accent1">` → "14B8A6".
     * Returns null when the themeKey is not found.
     */
    public function resolveColor(string $themeKey): ?string
    {
        return $this->colorMap[strtolower($themeKey)] ?? null;
    }

    public function resolveFont(string $themeKey): ?string
    {
        return $this->fontMap[strtolower($themeKey)] ?? null;
    }

    private function loadThemeXml(\DOMDocument $doc): void
    {
        $theme = $doc->documentElement;
        if ($theme === null) {
            return;
        }

        $themeElements = $theme->getElementsByTagNameNS(OoxmlNs::A, 'themeElements')->item(0);
        if (! $themeElements instanceof \DOMElement) {
            return;
        }

        // <a:clrScheme>
        $clrScheme = OoxmlNs::firstChild($themeElements, OoxmlNs::A, 'clrScheme');
        if ($clrScheme !== null) {
            $this->loadColorScheme($clrScheme);
        }

        // <a:fontScheme>
        $fontScheme = OoxmlNs::firstChild($themeElements, OoxmlNs::A, 'fontScheme');
        if ($fontScheme !== null) {
            $this->loadFontScheme($fontScheme);
        }
    }

    private function loadColorScheme(\DOMElement $clrScheme): void
    {
        // The children are <a:dk1>/<a:lt1>/<a:dk2>/<a:lt2>/<a:accent1..6>/
        // <a:hlink>/<a:folHlink>, each holding either <a:srgbClr val="…"/> or
        // <a:sysClr val="…" lastClr="…"/>.
        foreach ($clrScheme->childNodes as $child) {
            if (! $child instanceof \DOMElement || $child->namespaceURI !== OoxmlNs::A) {
                continue;
            }
            $key = strtolower($child->localName);
            $hex = $this->extractColorValue($child);
            if ($hex !== null) {
                $this->colorMap[$key] = $hex;
            }
        }
    }

    private function extractColorValue(\DOMElement $parent): ?string
    {
        $srgb = OoxmlNs::firstChild($parent, OoxmlNs::A, 'srgbClr');
        if ($srgb !== null) {
            $val = $srgb->getAttribute('val');
            if ($val !== '') {
                return strtolower($val);
            }
        }
        $sys = OoxmlNs::firstChild($parent, OoxmlNs::A, 'sysClr');
        if ($sys !== null) {
            $last = $sys->getAttribute('lastClr');
            if ($last !== '') {
                return strtolower($last);
            }
            // Fallback: known sysClr names.
            return match (strtolower($sys->getAttribute('val'))) {
                'windowtext' => '000000',
                'window' => 'ffffff',
                default => null,
            };
        }

        return null;
    }

    private function loadFontScheme(\DOMElement $fontScheme): void
    {
        // <a:majorFont> and <a:minorFont>, holding <a:latin typeface="…"/>.
        foreach (['majorFont' => 'majorAscii', 'minorFont' => 'minorAscii'] as $local => $key) {
            $node = OoxmlNs::firstChild($fontScheme, OoxmlNs::A, $local);
            if ($node === null) {
                continue;
            }
            $latin = OoxmlNs::firstChild($node, OoxmlNs::A, 'latin');
            if ($latin === null) {
                continue;
            }
            $face = $latin->getAttribute('typeface');
            if ($face !== '') {
                $this->fontMap[strtolower($key)] = $face;
                // Aliases for the CS/EA variants of the same font as well.
                $this->fontMap[str_replace('Ascii', 'HAnsi', $key) ?: ''] = $face;
            }
        }
    }
}
