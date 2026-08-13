<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Element;

/**
 * An image. It can be inline (inside a run) or a block (its own paragraph).
 *
 * binary holds the raw bytes of the file. It is stored in
 * word/media/imageN.ext and tied to the document through
 * _rels/document.xml.rels.
 *
 * Sizes are in EMU (English Metric Units): 1 inch = 914400 EMU,
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
        /**
         * The vertical offset of a floating object from its anchor point, in
         * EMU. A negative value means the object is drawn ABOVE its paragraph
         * and overlaps the preceding text: that is how Word places stamps and
         * signatures over a finished block. Zero is an ordinary image in the
         * flow.
         */
        public int $offsetYEmu = 0,
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
