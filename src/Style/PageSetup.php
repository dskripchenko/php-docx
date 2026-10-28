<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Style;

/**
 * The page setup (paper size, orientation, margins).
 *
 * The sizes are in twips (1pt = 20 twips, 1mm ≈ 56.7 twips). They are kept as
 * twips so that converting into OOXML involves no rounding.
 */
final readonly class PageSetup
{
    public function __construct(
        public PaperSize $paperSize = PaperSize::A4,
        public Orientation $orientation = Orientation::Portrait,
        public int $marginTopTwips = 1133,    // ~20mm
        public int $marginRightTwips = 850,   // ~15mm
        public int $marginBottomTwips = 1133, // ~20mm
        public int $marginLeftTwips = 850,    // ~15mm
        public int $headerOffsetTwips = 283,  // ~5mm от верха страницы
        public int $footerOffsetTwips = 283,  // ~5mm от низа страницы
        /**
         * A page size of its own, in twips, instead of `$paperSize`: both or
         * neither. The page is landscape when it is wider than it is tall;
         * `$orientation` is not applied to a custom size.
         */
        public ?int $widthTwips = null,
        public ?int $heightTwips = null,
    ) {
        if (($widthTwips === null) !== ($heightTwips === null)) {
            throw new \InvalidArgumentException('A custom page size needs both a width and a height.');
        }
        if ($widthTwips !== null && ($widthTwips <= 0 || $heightTwips <= 0)) {
            throw new \InvalidArgumentException('A custom page size must be positive.');
        }
    }

    public static function fromMm(
        PaperSize $paperSize = PaperSize::A4,
        Orientation $orientation = Orientation::Portrait,
        float $marginTopMm = 20,
        float $marginRightMm = 15,
        float $marginBottomMm = 20,
        float $marginLeftMm = 15,
    ): self {
        $toTwips = static fn (float $mm): int => (int) round($mm * 56.6929);

        return new self(
            $paperSize,
            $orientation,
            $toTwips($marginTopMm),
            $toTwips($marginRightMm),
            $toTwips($marginBottomMm),
            $toTwips($marginLeftMm),
        );
    }

    /**
     * The width of the content area (for computing table cell widths).
     */
    public function contentWidthTwips(): int
    {
        return $this->pageWidthTwips() - $this->marginLeftTwips - $this->marginRightTwips;
    }

    /** The page's width as laid out: the custom size, else the paper turned by the orientation. */
    public function pageWidthTwips(): int
    {
        return $this->widthTwips ?? ($this->orientation === Orientation::Portrait
            ? $this->paperSize->widthTwips()
            : $this->paperSize->heightTwips());
    }

    /** The page's height as laid out; see pageWidthTwips(). */
    public function pageHeightTwips(): int
    {
        return $this->heightTwips ?? ($this->orientation === Orientation::Portrait
            ? $this->paperSize->heightTwips()
            : $this->paperSize->widthTwips());
    }

    /** What `w:orient` says: landscape when the page is wider than it is tall. */
    public function effectiveOrientation(): Orientation
    {
        return $this->widthTwips === null
            ? $this->orientation
            : ($this->widthTwips > (int) $this->heightTwips ? Orientation::Landscape : Orientation::Portrait);
    }
}
