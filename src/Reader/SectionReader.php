<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Reader;

use Dskripchenko\PhpDocx\Style\Columns;
use Dskripchenko\PhpDocx\Style\Orientation;
use Dskripchenko\PhpDocx\Style\PageSetup;
use Dskripchenko\PhpDocx\Style\PaperSize;
use Dskripchenko\PhpDocx\Style\SectionStart;

/**
 * Phase 8 — SectionReader: `<w:sectPr>` → PageSetup, SectionStart and Columns.
 *
 * A Word document has one `<w:sectPr>` per section: DocxReader reads each with
 * fromSectPr(). readPageSetup() takes the LAST one in the body, the final
 * section's.
 */
final class SectionReader
{
    public function readPageSetup(\DOMElement $body): PageSetup
    {
        return $this->fromSectPr($this->findLastSectPr($body));
    }

    /** One section's `<w:sectPr>` → PageSetup (the defaults when null). */
    public function fromSectPr(?\DOMElement $sectPr): PageSetup
    {
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
        // A size no PaperSize matches is kept as it is.
        $custom = $paperSize === null && $widthTwips !== null && $heightTwips !== null && $widthTwips > 0 && $heightTwips > 0;

        return new PageSetup(
            paperSize: $paperSize ?? PaperSize::A4,
            orientation: $orientation,
            marginTopTwips: $marginTop,
            marginRightTwips: $marginRight,
            marginBottomTwips: $marginBottom,
            marginLeftTwips: $marginLeft,
            headerOffsetTwips: $headerOffset,
            footerOffsetTwips: $footerOffset,
            widthTwips: $custom ? $widthTwips : null,
            heightTwips: $custom ? $heightTwips : null,
        );
    }

    /** Where the section starts (`<w:type>`); a new page when absent or unknown. */
    public function start(?\DOMElement $sectPr): SectionStart
    {
        $type = $sectPr === null ? null : OoxmlNs::firstChild($sectPr, OoxmlNs::W, 'type');

        return $type === null ? SectionStart::NextPage : (SectionStart::tryFrom($type->getAttributeNS(OoxmlNs::W, 'val')) ?? SectionStart::NextPage);
    }

    /** The section's `<w:cols>`; null when it has none (a single column). */
    public function columns(?\DOMElement $sectPr): ?Columns
    {
        $cols = $sectPr === null ? null : OoxmlNs::firstChild($sectPr, OoxmlNs::W, 'cols');
        if ($cols === null) {
            return null;
        }
        $twips = static function (\DOMElement $el, string $attr): ?int {
            $v = $el->getAttributeNS(OoxmlNs::W, $attr);

            return ctype_digit($v) ? (int) $v : null;
        };
        $separator = in_array($cols->getAttributeNS(OoxmlNs::W, 'sep'), ['1', 'true', 'on'], true);
        $space = $twips($cols, 'space') ?? 720;

        $widths = [];
        $spaces = [];
        if (! in_array($cols->getAttributeNS(OoxmlNs::W, 'equalWidth'), ['0', 'false', 'off'], true)) {
            $widths = null;
        } else {
            foreach ($cols->childNodes as $col) {
                if ($col instanceof \DOMElement && $col->namespaceURI === OoxmlNs::W && $col->localName === 'col') {
                    $widths[] = $twips($col, 'w') ?? 0;
                    $spaces[] = $twips($col, 'space') ?? $space;
                }
            }
            // Unequal columns without a usable list read as equal ones.
            if ($widths === [] || min($widths) <= 0) {
                $widths = null;
            } else {
                array_pop($spaces);
            }
        }

        $num = $twips($cols, 'num');
        $count = $widths !== null ? count($widths) : max(1, $num ?? 1);

        // Word writes a bare <w:cols w:space="720"/> in ordinary documents:
        // that is a single column, which is null here.
        if ($widths === null && $count === 1 && ! $separator) {
            return null;
        }

        return new Columns($count, $space, $separator, $widths, $widths !== null ? $spaces : null);
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

    private function detectPaperSize(?int $w, ?int $h, Orientation $orientation): ?PaperSize
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

        return null;
    }
}
