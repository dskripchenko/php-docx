<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Reader;

/**
 * Источник обнаружения переменной в DOCX (ADR-015 Phase 9).
 */
enum VariableSource: string
{
    /** `<w:fldSimple>`/complex field с инструкцией `MERGEFIELD …`. */
    case MergeField = 'mergeField';

    /** `<w:sdt>` content control (Word 2013+). */
    case ContentControl = 'contentControl';

    /** Регулярки на тексте: `{{var}}`, `${var}`, `%var%`. */
    case TextPattern = 'textPattern';
}
