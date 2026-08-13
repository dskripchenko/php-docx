<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Element;

use Dskripchenko\PhpDocx\Style\RunStyle;

/**
 * A Word "field code" inline element — dynamic content computed by the
 * renderer. It maps to `<w:fldSimple w:instr="..."/>`.
 *
 * The usual FieldTypes:
 *   - `PAGE`     — the number of the current page
 *   - `NUMPAGES` — the total number of pages in the document
 *   - `DATE`     — the current date
 *   - `TIME`     — the current time
 *   - `AUTHOR`   — the author from docProps
 *   - `TITLE`    — the title from docProps
 *
 * The custom HTML tags in the converter:
 *   <page-number/>    → Field::page()
 *   <page-total/>     → Field::pageTotal()
 *   <current-date/>   → Field::date()
 */
final readonly class Field implements InlineElement
{
    public function __construct(
        public string $instruction,
        public RunStyle $style = new RunStyle,
    ) {}

    public static function page(RunStyle $style = new RunStyle): self
    {
        return new self('PAGE \\* MERGEFORMAT', $style);
    }

    public static function pageTotal(RunStyle $style = new RunStyle): self
    {
        return new self('NUMPAGES \\* MERGEFORMAT', $style);
    }

    public static function date(string $format = 'dd.MM.yyyy', RunStyle $style = new RunStyle): self
    {
        return new self('DATE \\@ "'.$format.'" \\* MERGEFORMAT', $style);
    }

    public static function time(string $format = 'HH:mm', RunStyle $style = new RunStyle): self
    {
        return new self('TIME \\@ "'.$format.'" \\* MERGEFORMAT', $style);
    }

    public static function author(RunStyle $style = new RunStyle): self
    {
        return new self('AUTHOR \\* MERGEFORMAT', $style);
    }

    public static function title(RunStyle $style = new RunStyle): self
    {
        return new self('TITLE \\* MERGEFORMAT', $style);
    }
}
