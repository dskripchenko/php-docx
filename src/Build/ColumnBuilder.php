<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Build;

/**
 * Fluent builder для одной table-column (`<w:gridCol>`).
 *
 * Сегодня хранит только ширину (`build(): int twips`). Создан как
 * отдельный класс чтобы будущие расширения (например, column-level
 * стили: hidden, expression, default-cell-style) могли быть добавлены
 * без breaking change'а на TableBuilder::columns(int...) API.
 *
 * Используется через `TableBuilder::column(fn(ColumnBuilder) => $c->widthCm(3))`.
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
