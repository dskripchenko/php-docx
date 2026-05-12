<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Element;

use Dskripchenko\PhpDocx\Style\RunStyle;

/**
 * Word "field code" inline element — динамическое содержимое, вычисляемое
 * рендерером. Мапится в `<w:fldSimple w:instr="..."/>`.
 *
 * Типичные FieldType:
 *   - `PAGE`     — номер текущей страницы
 *   - `NUMPAGES` — всего страниц в документе
 *   - `DATE`     — текущая дата
 *   - `TIME`     — текущее время
 *   - `AUTHOR`   — автор из docProps
 *   - `TITLE`    — заголовок из docProps
 *
 * Custom HTML-теги в Converter:
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
