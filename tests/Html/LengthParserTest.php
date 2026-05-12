<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Tests\Html;

use Dskripchenko\PhpDocx\Html\LengthParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LengthParserTest extends TestCase
{
    #[Test]
    public function it_parses_pt_to_twips(): void
    {
        self::assertSame(240, LengthParser::parseTwips('12pt'));
        self::assertSame(20, LengthParser::parseTwips('1pt'));
    }

    #[Test]
    public function it_parses_px_to_twips(): void
    {
        self::assertSame(15, LengthParser::parseTwips('1px'));
        self::assertSame(150, LengthParser::parseTwips('10px'));
    }

    #[Test]
    public function it_parses_cm_mm_in_to_twips(): void
    {
        self::assertSame(1440, LengthParser::parseTwips('1in'));
        self::assertSame(567, LengthParser::parseTwips('1cm'));
        self::assertSame(57, LengthParser::parseTwips('1mm'));
    }

    #[Test]
    public function it_returns_null_for_percent_via_parse_twips(): void
    {
        self::assertNull(LengthParser::parseTwips('50%'));
        self::assertNull(LengthParser::parseTwips('auto'));
        self::assertNull(LengthParser::parseTwips('inherit'));
    }

    #[Test]
    public function it_parses_percent(): void
    {
        self::assertSame(50.0, LengthParser::parsePercent('50%'));
        self::assertSame(33.33, LengthParser::parsePercent('33.33%'));
        self::assertNull(LengthParser::parsePercent('12pt'));
    }

    #[Test]
    public function percent_to_ooxml_pct_multiplies_by_50(): void
    {
        self::assertSame(2500, LengthParser::percentToOoxmlPct(50.0));
        self::assertSame(5000, LengthParser::percentToOoxmlPct(100.0));
        self::assertSame(1800, LengthParser::percentToOoxmlPct(36.0));
    }

    #[Test]
    public function font_size_returns_half_points(): void
    {
        self::assertSame(24, LengthParser::parseFontSizeHalfPoints('12pt'));
        self::assertSame(32, LengthParser::parseFontSizeHalfPoints('16pt'));
    }
}
