<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Reader;

/**
 * Phase 8 — extracts the watermark text from the header XML.
 *
 * Two formats are supported:
 *
 * 1. **VML** (legacy: our writer and older Word):
 *    `<v:shape type="#_x0000_t136"><v:textpath string="…"/></v:shape>`
 *    In a DOCX file it is usually wrapped in `<w:pict>` inside `<w:r>`.
 *
 * 2. **DrawingML SDT** (Word 2013+):
 *    `<w:sdt>` with `<w:sdtPr><w:tag w:val="..."/></w:sdtPr>` plus
 *    `wp14:Watermark` children. The watermark text sits in any `<w:t>` inside
 *    the SDT.
 *
 * Mutates `$headerDoc`: the watermark shape/SDT is removed from the DOM so that
 * BodyReader does not pull it out as an ordinary run.
 */
final class WatermarkExtractor
{
    /**
     * Extracts the watermark text from the header DOMDocument. Returns null
     * when there is none.
     */
    public function extract(\DOMDocument $headerDoc): ?string
    {
        $text = $this->extractVml($headerDoc);
        if ($text !== null) {
            return $text;
        }

        return $this->extractSdt($headerDoc);
    }

    private function extractVml(\DOMDocument $doc): ?string
    {
        $shapes = $doc->getElementsByTagNameNS(OoxmlNs::V, 'shape');
        $found = null;
        // Find first, remove separately afterwards (mutating the collection
        // during iteration is not safe).
        $shapesToRemove = [];
        for ($i = 0; $i < $shapes->length; $i++) {
            $shape = $shapes->item($i);
            if (! $shape instanceof \DOMElement) {
                continue;
            }
            $type = $shape->getAttribute('type');
            if (! str_contains($type, '_t136')) {
                continue;
            }
            $textpath = OoxmlNs::firstChild($shape, OoxmlNs::V, 'textpath');
            if ($textpath === null) {
                continue;
            }
            $str = $textpath->getAttribute('string');
            if ($str === '') {
                continue;
            }
            if ($found === null) {
                $found = $str;
            }
            $shapesToRemove[] = $shape;
        }

        foreach ($shapesToRemove as $shape) {
            $this->removeWithEmptyParents($shape);
        }

        return $found;
    }

    private function extractSdt(\DOMDocument $doc): ?string
    {
        // SDTs can appear in any context; take the watermarks only.
        $sdts = $doc->getElementsByTagNameNS(OoxmlNs::W, 'sdt');
        $sdtsToRemove = [];
        $found = null;
        for ($i = 0; $i < $sdts->length; $i++) {
            $sdt = $sdts->item($i);
            if (! $sdt instanceof \DOMElement) {
                continue;
            }
            if (! $this->isWatermarkSdt($sdt)) {
                continue;
            }
            $text = $this->collectInnerText($sdt);
            if ($text !== '' && $found === null) {
                $found = $text;
            }
            $sdtsToRemove[] = $sdt;
        }
        foreach ($sdtsToRemove as $sdt) {
            $this->removeWithEmptyParents($sdt);
        }

        return $found;
    }

    private function isWatermarkSdt(\DOMElement $sdt): bool
    {
        $sdtPr = OoxmlNs::firstChild($sdt, OoxmlNs::W, 'sdtPr');
        if ($sdtPr === null) {
            return false;
        }
        // Tag/alias contains "watermark" (case-insensitive)
        foreach (['tag', 'alias'] as $attrTag) {
            $el = OoxmlNs::firstChild($sdtPr, OoxmlNs::W, $attrTag);
            if ($el === null) {
                continue;
            }
            $val = OoxmlNs::wVal($el) ?? '';
            if (str_contains(strtolower($val), 'watermark')) {
                return true;
            }
        }

        return false;
    }

    private function collectInnerText(\DOMElement $sdt): string
    {
        $text = '';
        foreach ($sdt->getElementsByTagNameNS(OoxmlNs::W, 't') as $t) {
            if ($t instanceof \DOMElement) {
                $text .= $t->textContent;
            }
        }

        return trim($text);
    }

    private function removeWithEmptyParents(\DOMElement $node): void
    {
        $parent = $node->parentNode;
        $node->parentNode?->removeChild($node);
        // Clean up the wrappers (w:pict, w:r, w:p) that would be left empty
        // once the watermark is removed.
        while ($parent instanceof \DOMElement) {
            if ($parent->hasChildNodes()) {
                // Check for non-text elements or non-empty text only.
                $allEmpty = true;
                foreach ($parent->childNodes as $c) {
                    if ($c instanceof \DOMElement) {
                        $allEmpty = false;
                        break;
                    }
                    if ($c instanceof \DOMText && trim($c->textContent) !== '') {
                        $allEmpty = false;
                        break;
                    }
                }
                if (! $allEmpty) {
                    return;
                }
            }
            $next = $parent->parentNode;
            // Never drop the root <w:hdr>/<w:ftr>.
            if (! $next instanceof \DOMElement) {
                return;
            }
            $next->removeChild($parent);
            $parent = $next;
        }
    }
}
