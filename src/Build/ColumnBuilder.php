<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Build;

/**
 * A fluent builder for a single table column (`<w:gridCol>`).
 *
 * Today it holds nothing but the width (`build(): int twips`). It exists as a
 * separate class so that future extensions (column-level styles: hidden,
 * expression, a default cell style) can be added without breaking the
 * TableBuilder::columns(int...) API.
 *
 * Used through `TableBuilder::column(fn(ColumnBuilder) => $c->widthCm(3))`.
 */
final class ColumnBuilder
{
    private int $widthTwips = 2000;

    public function widthTwips(int $twips): self
    {
        $this->widthTwips = max(0, $twips);

        return $this;
    }

    public function widthPt(float $pt): self
    {
        return $this->widthTwips(Length::pt($pt));
    }

    public function widthMm(float $mm): self
    {
        return $this->widthTwips(Length::mm($mm));
    }

    public function widthCm(float $cm): self
    {
        return $this->widthTwips(Length::cm($cm));
    }

    public function widthInches(float $inches): self
    {
        return $this->widthTwips(Length::inch($inches));
    }

    public function widthPx(float $px): self
    {
        return $this->widthTwips(Length::px($px));
    }

    public function build(): int
    {
        return $this->widthTwips;
    }
}
