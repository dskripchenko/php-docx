<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Style;

/**
 * A set of borders (top/right/bottom/left) for a table, cell or paragraph.
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
     * All four sides (plus insideH/V for a table) of the same kind.
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
