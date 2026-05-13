<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Reader;

/**
 * Обнаруженная переменная — IR между reader'ом и importer'ом
 * на стороне application (printable).
 *
 * Importer сам решает как этот IR конвертировать в свой синтаксис
 * placeholder'ов (`{{name}}`, `<var data-name>`, etc.).
 */
final readonly class DetectedVariable
{
    public function __construct(
        public string $name,
        public VariableSource $source,
        /** Plain-text placeholder, как он встречается в документе (для замены). */
        public string $placeholder,
        /** Sample-value, отрисованный Word'ом (для preview в admin UI). */
        public ?string $sampleValue = null,
    ) {}
}
