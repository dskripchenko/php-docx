<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Element;

/**
 * Inline-перенос строки (`<w:br/>`). Не путать с `PageBreak` (block-level).
 */
final readonly class LineBreak implements InlineElement {}
