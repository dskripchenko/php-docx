<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Style;

/**
 * The standard paper sizes. The values are in twips (portrait orientation).
 */
enum PaperSize: string
{
    case A3 = 'A3';
    case A4 = 'A4';
    case A5 = 'A5';
    case Letter = 'Letter';
    case Legal = 'Legal';

    public function widthTwips(): int
    {
        return match ($this) {
            self::A3 => 16839,     // 297mm
            self::A4 => 11906,     // 210mm
            self::A5 => 8392,      // 148mm
            self::Letter => 12240, // 8.5in
            self::Legal => 12240,  // 8.5in
        };
    }

    public function heightTwips(): int
    {
        return match ($this) {
            self::A3 => 23814,     // 420mm
            self::A4 => 16839,     // 297mm
            self::A5 => 11906,     // 210mm
            self::Letter => 15840, // 11in
            self::Legal => 20160,  // 14in
        };
    }
}
