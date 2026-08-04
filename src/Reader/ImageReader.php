<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Reader;

use Dskripchenko\PhpDocx\Element\Image;
use Dskripchenko\PhpDocx\Element\ImageFormat;

/**
 * Phase 6 — ImageReader.
 *
 * Парсит `<w:drawing>` (inline или anchor) → Element\Image:
 *  - `<a:blip r:embed="rIdN"/>` → resolve via partRels → media bytes
 *  - `<wp:extent cx="..." cy="..."/>` → размер в EMU
 *  - `<wp:docPr descr="..."/>` → alt text
 *
 * Возвращает null если:
 *  - нет blip с r:embed
 *  - rel не resolved
 *  - бинарь отсутствует или формат не поддерживается
 *
 * Anchored picture (`<wp:anchor>`) обрабатывается как inline — Word
 * читатель в HTML контексте не имеет понятия "float position".
 */
final class ImageReader
{
    public function __construct(
        private readonly DocxPackage $package,
        private readonly string $ownerPartPath,
    ) {}

    public function read(\DOMElement $drawing): ?Image
    {
        // wp:inline или wp:anchor.
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

        // Смещение по вертикали — только у плавающего объекта (wp:anchor).
        $offsetYEmu = 0;
        $positionV = OoxmlNs::firstChild($container, OoxmlNs::WP, 'positionV');
        if ($positionV !== null) {
            $posOffset = OoxmlNs::firstChild($positionV, OoxmlNs::WP, 'posOffset');
            if ($posOffset !== null && preg_match('/^-?\d+$/', trim($posOffset->textContent)) === 1) {
                $offsetYEmu = (int) trim($posOffset->textContent);
            }
        }

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

        // Ищем blip — он может быть в произвольной глубине внутри
        // a:graphic > a:graphicData > pic:pic > pic:blipFill > a:blip.
        // Безопаснее — getElementsByTagNameNS.
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

        // Fallback размеры — getimagesizefromstring если extent отсутствовал.
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
        );
    }

    private function detectFormat(string $zipPath, string $bytes): ?ImageFormat
    {
        // Сначала по расширению (быстро).
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
        // TIFF: little-endian (II*\0) или big-endian (MM\0*).
        if (str_starts_with($bytes, "II\x2A\x00") || str_starts_with($bytes, "MM\x00\x2A")) {
            return ImageFormat::Tiff;
        }

        return null;
    }
}
