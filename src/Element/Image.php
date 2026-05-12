<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Element;

/**
 * Картинка. Может быть inline (внутри Run) или block (отдельным параграфом).
 *
 * binary — сырые байты файла. Хранится в word/media/imageN.ext, связывается
 * с документом через _rels/document.xml.rels.
 *
 * Размеры в EMU (English Metric Units): 1 inch = 914400 EMU,
 * 1 cm = 360000 EMU, 1 px (@96 DPI) = 9525 EMU.
 */
final readonly class Image implements InlineElement, BlockElement
{
    public function __construct(
        public string $binary,
        public ImageFormat $format,
        public int $widthEmu,
        public int $heightEmu,
        public ?string $altText = null,
    ) {}

    public static function fromPx(
        string $binary,
        ImageFormat $format,
        int $widthPx,
        int $heightPx,
        ?string $altText = null,
    ): self {
        return new self(
            binary: $binary,
            format: $format,
            widthEmu: $widthPx * 9525,
            heightEmu: $heightPx * 9525,
            altText: $altText,
        );
    }
}
