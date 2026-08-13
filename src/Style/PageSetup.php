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
    ) {}

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
        [$pageWidth, ] = $this->orientation === Orientation::Portrait
            ? [$this->paperSize->widthTwips(), $this->paperSize->heightTwips()]
            : [$this->paperSize->heightTwips(), $this->paperSize->widthTwips()];

        return $pageWidth - $this->marginLeftTwips - $this->marginRightTwips;
    }
}
