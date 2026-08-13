<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Element;

/**
 * The format of an ordered list. It matches the HTML `<ol type="...">` and the
 * OOXML `<w:numFmt w:val="...">`.
 */
enum ListFormat: string
{
    case Bullet = 'bullet';
    case Decimal = 'decimal';            // 1, 2, 3
    case LowerLetter = 'lowerLetter';    // a, b, c (ol type="a")
    case UpperLetter = 'upperLetter';    // A, B, C (ol type="A")
    case LowerRoman = 'lowerRoman';      // i, ii, iii (ol type="i")
    case UpperRoman = 'upperRoman';      // I, II, III (ol type="I")

    public static function fromHtmlType(?string $type, bool $ordered): self
    {
        if (! $ordered) {
            return self::Bullet;
        }

        return match ($type) {
            'a' => self::LowerLetter,
            'A' => self::UpperLetter,
            'i' => self::LowerRoman,
            'I' => self::UpperRoman,
            default => self::Decimal,
        };
    }
}
