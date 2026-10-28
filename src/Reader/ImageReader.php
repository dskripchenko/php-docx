<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Reader;

use Dskripchenko\PhpDocx\Element\Image;
use Dskripchenko\PhpDocx\Element\ImageFormat;
use Dskripchenko\PhpDocx\Style\HorizontalAnchor;
use Dskripchenko\PhpDocx\Style\ImagePosition;
use Dskripchenko\PhpDocx\Style\ImageWrap;
use Dskripchenko\PhpDocx\Style\PositionAlign;
use Dskripchenko\PhpDocx\Style\VerticalAnchor;

/**
 * Phase 6 — ImageReader.
 *
 * Parses `<w:drawing>` (inline or anchor) → Element\Image:
 *  - `<a:blip r:embed="rIdN"/>` → resolve via partRels → media bytes
 *  - `<wp:extent cx="..." cy="..."/>` → the size in EMU
 *  - `<wp:docPr descr="..."/>` → alt text
 *
 * Returns null when:
 *  - there is no blip with r:embed
 *  - the rel does not resolve
 *  - the binary is missing or its format is unsupported
 *
 * An anchored picture (`<wp:anchor>`) keeps its position (Image::$position)
 * and, as before, its vertical offset in `offsetYEmu`, which is all the HTML
 * serializer uses of it.
 */
final class ImageReader
{
    public function __construct(
        private readonly DocxPackage $package,
        private readonly string $ownerPartPath,
    ) {}

    public function read(\DOMElement $drawing): ?Image
    {
        // Either wp:inline or wp:anchor.
        $container = OoxmlNs::firstChild($drawing, OoxmlNs::WP, 'inline')
            ?? OoxmlNs::firstChild($drawing, OoxmlNs::WP, 'anchor');
        if ($container === null) {
            return null;
        }

        $extent = OoxmlNs::firstChild($container, OoxmlNs::WP, 'extent');
        $widthEmu = 0;
        $heightEmu = 0;
        if ($extent !== null) {
            $cx = $extent->getAttribute('cx');
            $cy = $extent->getAttribute('cy');
            if (ctype_digit($cx)) {
                $widthEmu = (int) $cx;
            }
            if (ctype_digit($cy)) {
                $heightEmu = (int) $cy;
            }
        }

        // Only a floating object (wp:anchor) has a vertical offset.
        $offsetYEmu = 0;
        $positionV = OoxmlNs::firstChild($container, OoxmlNs::WP, 'positionV');
        if ($positionV !== null) {
            $posOffset = OoxmlNs::firstChild($positionV, OoxmlNs::WP, 'posOffset');
            if ($posOffset !== null && preg_match('/^-?\d+$/', trim($posOffset->textContent)) === 1) {
                $offsetYEmu = (int) trim($posOffset->textContent);
            }
        }

        $position = $container->localName === 'anchor' ? self::position($container) : null;
        $docPr = OoxmlNs::firstChild($container, OoxmlNs::WP, 'docPr');
        $altText = null;
        if ($docPr !== null) {
            $descr = $docPr->getAttribute('descr');
            $title = $docPr->getAttribute('title');
            if ($descr !== '') {
                $altText = $descr;
            } elseif ($title !== '') {
                $altText = $title;
            }
        }

        // Look for the blip: it can sit at an arbitrary depth inside
        // a:graphic > a:graphicData > pic:pic > pic:blipFill > a:blip, so
        // getElementsByTagNameNS is the safer way.
        $blip = $drawing->getElementsByTagNameNS(OoxmlNs::A, 'blip')->item(0);
        if (! $blip instanceof \DOMElement) {
            return null;
        }
        $embedRId = $blip->getAttributeNS(OoxmlNs::R, 'embed');
        if ($embedRId === '') {
            return null;
        }

        try {
            $rel = $this->package->resolveRel($this->ownerPartPath, $embedRId);
        } catch (\Throwable) {
            return null;
        }
        if ($rel->isExternal()) {
            return null; // external images — пока skip
        }

        $zipPath = $this->package->resolveMediaPath($this->ownerPartPath, $rel->target);
        $bytes = $this->package->mediaBytes($zipPath);
        if ($bytes === null || $bytes === '') {
            return null;
        }

        $format = $this->detectFormat($zipPath, $bytes);
        if ($format === null) {
            return null;
        }

        // Fallback size via getimagesizefromstring when there was no extent.
        if ($widthEmu <= 0 || $heightEmu <= 0) {
            $info = @getimagesizefromstring($bytes);
            if ($info !== false) {
                if ($widthEmu <= 0) {
                    $widthEmu = (int) $info[0] * 9525;
                }
                if ($heightEmu <= 0) {
                    $heightEmu = (int) $info[1] * 9525;
                }
            }
        }
        if ($widthEmu <= 0) {
            $widthEmu = 1;
        }
        if ($heightEmu <= 0) {
            $heightEmu = 1;
        }

        return new Image(
            binary: $bytes,
            format: $format,
            widthEmu: $widthEmu,
            heightEmu: $heightEmu,
            altText: $altText,
            offsetYEmu: $offsetYEmu,
            position: $position,
        );
    }

    /**
     * A `<wp:anchor>`'s position. What the model has no word for is read as
     * the nearest it has: a `*Margin` origin as the margin, a tight or
     * through wrap as square.
     */
    private static function position(\DOMElement $anchor): ImagePosition
    {
        $axis = static function (string $tag) use ($anchor): array {
            $el = OoxmlNs::firstChild($anchor, OoxmlNs::WP, $tag);
            if ($el === null) {
                return ['', null, 0];
            }
            $align = OoxmlNs::firstChild($el, OoxmlNs::WP, 'align');
            $offset = OoxmlNs::firstChild($el, OoxmlNs::WP, 'posOffset');
            $value = $offset === null ? '' : trim($offset->textContent);

            return [
                $el->getAttribute('relativeFrom'),
                $align === null ? null : PositionAlign::tryFrom(trim($align->textContent)),
                preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : 0,
            ];
        };
        [$hFrom, $hAlign, $x] = $axis('positionH');
        [$vFrom, $vAlign, $y] = $axis('positionV');
        $margin = static fn (string $from): bool => str_ends_with($from, 'Margin');

        $wrap = ImageWrap::None;
        foreach ($anchor->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->namespaceURI === OoxmlNs::WP) {
                $wrap = match ($child->localName) {
                    'wrapSquare', 'wrapTight', 'wrapThrough' => ImageWrap::Square,
                    'wrapTopAndBottom' => ImageWrap::TopAndBottom,
                    default => $wrap,
                };
            }
        }
        $height = $anchor->getAttribute('relativeHeight');
        $behind = strtolower($anchor->getAttribute('behindDoc'));

        return new ImagePosition(
            horizontalFrom: HorizontalAnchor::tryFrom($hFrom) ?? ($margin($hFrom) ? HorizontalAnchor::Margin : HorizontalAnchor::Column),
            xEmu: $x,
            verticalFrom: VerticalAnchor::tryFrom($vFrom) ?? ($margin($vFrom) ? VerticalAnchor::Margin : VerticalAnchor::Paragraph),
            yEmu: $y,
            horizontalAlign: $hAlign === PositionAlign::Top || $hAlign === PositionAlign::Bottom ? null : $hAlign,
            verticalAlign: $vAlign === PositionAlign::Left || $vAlign === PositionAlign::Right ? null : $vAlign,
            behindText: $behind === '1' || $behind === 'true' || $behind === 'on',
            wrap: $wrap,
            zOrder: ctype_digit($height) ? (int) $height : 0,
        );
    }

    private function detectFormat(string $zipPath, string $bytes): ?ImageFormat
    {
        // By extension first (it is cheap).
        $ext = strtolower(pathinfo($zipPath, PATHINFO_EXTENSION));
        $byExt = match ($ext) {
            'png' => ImageFormat::Png,
            'jpg', 'jpeg' => ImageFormat::Jpeg,
            'gif' => ImageFormat::Gif,
            'bmp' => ImageFormat::Bmp,
            'tif', 'tiff' => ImageFormat::Tiff,
            default => null,
        };
        if ($byExt !== null) {
            return $byExt;
        }
        // Fallback — magic-bytes.
        if (str_starts_with($bytes, "\x89PNG\r\n\x1A\n")) {
            return ImageFormat::Png;
        }
        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return ImageFormat::Jpeg;
        }
        if (str_starts_with($bytes, "GIF87a") || str_starts_with($bytes, "GIF89a")) {
            return ImageFormat::Gif;
        }
        if (str_starts_with($bytes, 'BM')) {
            return ImageFormat::Bmp;
        }
        // TIFF: little-endian (II*\0) or big-endian (MM\0*).
        if (str_starts_with($bytes, "II\x2A\x00") || str_starts_with($bytes, "MM\x00\x2A")) {
            return ImageFormat::Tiff;
        }

        return null;
    }
}
