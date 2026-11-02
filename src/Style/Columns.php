<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Style;

/**
 * A section's text columns (`<w:cols>` in its `<w:sectPr>`).
 *
 * Equal columns share the width left between the margins, with `$spaceTwips`
 * between each two. Unequal columns give each column its own width in
 * `$widthsTwips` (one per column) and, optionally, each gap its own space in
 * `$spacesTwips` (one fewer than there are columns); a gap without one uses
 * `$spaceTwips`. `$separator` draws a line between the columns.
 *
 * Text flows down one column and on into the next; `ColumnBreak` moves it to
 * the next column early.
 */
final readonly class Columns
{
    /**
     * @param  list<int>|null  $widthsTwips  One width per column; null for equal columns.
     * @param  list<int>|null  $spacesTwips  One space per gap between columns (count − 1); only with `$widthsTwips`.
     */
    public function __construct(
        public int $count = 2,
        public int $spaceTwips = 720, // 0.5in, Word's default
        public bool $separator = false,
        public ?array $widthsTwips = null,
        public ?array $spacesTwips = null,
    ) {
        if ($count < 1) {
            throw new \InvalidArgumentException('A section needs at least one column.');
        }
        if ($spaceTwips < 0) {
            throw new \InvalidArgumentException('The space between columns cannot be negative.');
        }
        if ($widthsTwips === null) {
            if ($spacesTwips !== null) {
                throw new \InvalidArgumentException('Per-gap spaces need per-column widths.');
            }

            return;
        }
        if (count($widthsTwips) !== $count) {
            throw new \InvalidArgumentException('Column widths need one width per column.');
        }
        foreach ($widthsTwips as $width) {
            if ($width <= 0) {
                throw new \InvalidArgumentException('A column width must be positive.');
            }
        }
        if ($spacesTwips !== null) {
            if (count($spacesTwips) !== $count - 1) {
                throw new \InvalidArgumentException('Column spaces need one space per gap between columns.');
            }
            foreach ($spacesTwips as $space) {
                if ($space < 0) {
                    throw new \InvalidArgumentException('The space between columns cannot be negative.');
                }
            }
        }
    }

    public function isEqualWidth(): bool
    {
        return $this->widthsTwips === null;
    }

    /** The space after column `$index` (0-based), the common one unless set per gap. */
    public function spaceAfter(int $index): int
    {
        return $this->spacesTwips[$index] ?? $this->spaceTwips;
    }
}
