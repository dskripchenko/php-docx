<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Style;

/**
 * Набор бордеров (top/right/bottom/left) для table/cell/paragraph.
 */
final readonly class BorderSet
{
    public function __construct(
        public ?Border $top = null,
        public ?Border $right = null,
        public ?Border $bottom = null,
        public ?Border $left = null,
        public ?Border $insideH = null,
        public ?Border $insideV = null,
    ) {}

    /**
     * Все 4 стороны (включая insideH/V для table) одного типа.
     */
    public static function all(Border $border, bool $withInside = true): self
    {
        return new self(
            top: $border,
            right: $border,
            bottom: $border,
            left: $border,
            insideH: $withInside ? $border : null,
            insideV: $withInside ? $border : null,
        );
    }

    public static function none(): self
    {
        $none = new Border(BorderStyle::None);

        return new self($none, $none, $none, $none, $none, $none);
    }
}
