<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Reader;

/**
 * How a variable was detected in the DOCX (ADR-015 Phase 9).
 */
enum VariableSource: string
{
    /** A `<w:fldSimple>`/complex field with a `MERGEFIELD …` instruction. */
    case MergeField = 'mergeField';

    /** `<w:sdt>` content control (Word 2013+). */
    case ContentControl = 'contentControl';

    /** Regexes over the text: `{{var}}`, `${var}`, `%var%`. */
    case TextPattern = 'textPattern';
}
