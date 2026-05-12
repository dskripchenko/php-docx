<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Tests\Html;

use Dskripchenko\PhpDocx\Html\ColorParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ColorParserTest extends TestCase
{
    #[Test]
    #[DataProvider('cases')]
    public function it_parses_color_to_hex(?string $input, ?string $expected): void
    {
        self::assertSame($expected, ColorParser::parse($input));
    }

    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function cases(): iterable
    {
        yield 'null' => [null, null];
        yield 'empty' => ['', null];
        yield 'short hex' => ['#fff', 'ffffff'];
        yield 'short hex uppercase' => ['#F0A', 'ff00aa'];
        yield 'full hex' => ['#14b8a6', '14b8a6'];
        yield 'rgb' => ['rgb(20, 184, 166)', '14b8a6'];
        yield 'rgb tight' => ['rgb(20,184,166)', '14b8a6'];
        yield 'rgba' => ['rgba(20, 184, 166, 0.5)', '14b8a6'];
        yield 'named red' => ['red', 'ff0000'];
        yield 'named teal' => ['teal', '008080'];
        yield 'unknown name' => ['plotbluefarbe', null];
        yield 'transparent' => ['transparent', null];
        yield 'inherit' => ['inherit', null];
    }
}
