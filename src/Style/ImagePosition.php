<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Style;

/**
 * Where a floating picture sits (`<wp:anchor>`): on each axis, an offset in
 * EMU from what it is measured from, or an alignment within it instead. A
 * picture with a position is drawn in front of the text (or behind it, with
 * `$behindText`), and text flows around it as `$wrap` says.
 *
 * A picture positioned on the page inside a header or footer is drawn on
 * every page the header governs: a letterhead, a page background, a stamp.
 * `$zOrder` stacks floating pictures (`relativeHeight`): higher is in front.
 */
final readonly class ImagePosition
{
    public function __construct(
        public HorizontalAnchor $horizontalFrom = HorizontalAnchor::Column,
        public int $xEmu = 0,
        public VerticalAnchor $verticalFrom = VerticalAnchor::Paragraph,
        public int $yEmu = 0,
        /** Aligns the picture horizontally instead of placing it at `$xEmu`. */
        public ?PositionAlign $horizontalAlign = null,
        /** Aligns the picture vertically instead of placing it at `$yEmu`. */
        public ?PositionAlign $verticalAlign = null,
        public bool $behindText = false,
        public ImageWrap $wrap = ImageWrap::None,
        public int $zOrder = 0,
    ) {
        if ($horizontalAlign === PositionAlign::Top || $horizontalAlign === PositionAlign::Bottom) {
            throw new \InvalidArgumentException('A horizontal alignment is left, right, center, inside or outside.');
        }
        if ($verticalAlign === PositionAlign::Left || $verticalAlign === PositionAlign::Right) {
            throw new \InvalidArgumentException('A vertical alignment is top, bottom, center, inside or outside.');
        }
        if ($zOrder < 0) {
            throw new \InvalidArgumentException('The z-order of a picture cannot be negative.');
        }
    }
}
