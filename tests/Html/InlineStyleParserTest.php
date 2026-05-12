<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Tests\Html;

use Dskripchenko\PhpDocx\Html\InlineStyleParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class InlineStyleParserTest extends TestCase
{
    #[Test]
    public function it_parses_simple_declarations(): void
    {
        $out = InlineStyleParser::parse('color: red; font-size: 12pt');
        self::assertSame(['color' => 'red', 'font-size' => '12pt'], $out);
    }

    #[Test]
    public function it_handles_trailing_semicolon(): void
    {
        self::assertSame(['color' => 'red'], InlineStyleParser::parse('color: red;'));
    }

    #[Test]
    public function it_handles_extra_whitespace(): void
    {
        $out = InlineStyleParser::parse('  color :  red ;   font-size :12pt  ;');
        self::assertSame(['color' => 'red', 'font-size' => '12pt'], $out);
    }

    #[Test]
    public function it_lowercases_properties_but_keeps_values(): void
    {
        $out = InlineStyleParser::parse('FONT-WEIGHT: Bold; Color: #14B8A6');
        self::assertSame(['font-weight' => 'Bold', 'color' => '#14B8A6'], $out);
    }

    #[Test]
    public function it_returns_empty_for_invalid_input(): void
    {
        self::assertSame([], InlineStyleParser::parse(null));
        self::assertSame([], InlineStyleParser::parse(''));
        self::assertSame([], InlineStyleParser::parse('not-a-declaration'));
    }

    #[Test]
    public function it_preserves_inline_url_without_semicolons(): void
    {
        // Простые url() (без `;` внутри) — ОК. CSS data-URLs со `;` не в
        // scope парсера (caller использует <img src> для картинок).
        $out = InlineStyleParser::parse("background: url('icon.png')");
        self::assertSame(['background' => "url('icon.png')"], $out);
    }
}
