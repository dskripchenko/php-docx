<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Reader;

use Dskripchenko\PhpDocx\Style\Orientation;
use Dskripchenko\PhpDocx\Style\PageSetup;
use Dskripchenko\PhpDocx\Style\PaperSize;

/**
 * Phase 8 — SectionReader: `<w:sectPr>` → PageSetup.
 *
 * A Word document may have several `<w:sectPr>` (one per section); we take the
 * LAST one in the body (it defines the default for every page up to the next
 * sectPr above). Multi-section documents with differing orientation or margins
 * are out of scope (ADR-015) — warn upstream.
 */
final class SectionReader
{
    public function readPageSetup(\DOMElement $body): PageSetup
    {
        $sectPr = $this->findLastSectPr($body);
        if ($sectPr === null) {
            return new PageSetup;
        }
        $orientation = Orientation::Portrait;
        $widthTwips = null;
        $heightTwips = null;
        $marginTop = 1133;
        $marginRight = 850;
        $marginBottom = 1133;
        $marginLeft = 850;
        $headerOffset = 283;
        $footerOffset = 283;

        $pgSz = OoxmlNs::firstChild($sectPr, OoxmlNs::W, 'pgSz');
        if ($pgSz !== null) {
            $w = $pgSz->getAttributeNS(OoxmlNs::W, 'w');
            $h = $pgSz->getAttributeNS(OoxmlNs::W, 'h');
            if (ctype_digit($w)) {
                $widthTwips = (int) $w;
            }
            if (ctype_digit($h)) {
                $heightTwips = (int) $h;
            }
            $orient = $pgSz->getAttributeNS(OoxmlNs::W, 'orient');
            if ($orient === 'landscape') {
                $orientation = Orientation::Landscape;
            }
        }

        $pgMar = OoxmlNs::firstChild($sectPr, OoxmlNs::W, 'pgMar');
        if ($pgMar !== null) {
            foreach ([
                'top' => &$marginTop,
                'right' => &$marginRight,
                'bottom' => &$marginBottom,
                'left' => &$marginLeft,
                'header' => &$headerOffset,
                'footer' => &$footerOffset,
            ] as $attr => &$target) {
                if ($pgMar->hasAttributeNS(OoxmlNs::W, $attr)) {
                    $v = $pgMar->getAttributeNS(OoxmlNs::W, $attr);
                    if (ctype_digit($v) || (str_starts_with($v, '-') && ctype_digit(substr($v, 1)))) {
                        $target = (int) $v;
                    }
                }
            }
        }

        $paperSize = $this->detectPaperSize($widthTwips, $heightTwips, $orientation);

        return new PageSetup(
            paperSize: $paperSize,
            orientation: $orientation,
            marginTopTwips: $marginTop,
            marginRightTwips: $marginRight,
            marginBottomTwips: $marginBottom,
            marginLeftTwips: $marginLeft,
            headerOffsetTwips: $headerOffset,
            footerOffsetTwips: $footerOffset,
        );
    }

    private function findLastSectPr(\DOMElement $body): ?\DOMElement
    {
        $last = null;
        foreach ($body->childNodes as $c) {
            if ($c instanceof \DOMElement && $c->namespaceURI === OoxmlNs::W && $c->localName === 'sectPr') {
                $last = $c;
            }
        }
        // Also check the last <w:p>/<w:pPr>/<w:sectPr> (Word sometimes puts the
        // sectPr inside the pPr of the final paragraph).
        if ($last === null) {
            $paragraphs = $body->getElementsByTagNameNS(OoxmlNs::W, 'p');
            for ($i = $paragraphs->length - 1; $i >= 0; $i--) {
                $p = $paragraphs->item($i);
                if (! $p instanceof \DOMElement) {
                    continue;
                }
                $pPr = OoxmlNs::firstChild($p, OoxmlNs::W, 'pPr');
                if ($pPr === null) {
                    continue;
                }
                $inner = OoxmlNs::firstChild($pPr, OoxmlNs::W, 'sectPr');
                if ($inner !== null) {
                    return $inner;
                }
            }
        }

        return $last;
    }

    private function detectPaperSize(?int $w, ?int $h, Orientation $orientation): PaperSize
    {
        if ($w === null || $h === null) {
            return PaperSize::A4;
        }
        // Normalize: for landscape we assume OOXML has already swapped w/h, so
        // the comparison is against the "long side".
        $longSide = max($w, $h);
        $shortSide = min($w, $h);
        // Tolerance ~50 twips (~0.9mm).
        foreach (PaperSize::cases() as $case) {
            $caseLong = max($case->widthTwips(), $case->heightTwips());
            $caseShort = min($case->widthTwips(), $case->heightTwips());
            if (abs($caseLong - $longSide) < 50 && abs($caseShort - $shortSide) < 50) {
                return $case;
            }
        }

        return PaperSize::A4;
    }
}
