<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Element;

/**
 * An inline line break (`<w:br/>`). Not to be confused with `PageBreak`, which
 * is block-level.
 */
final readonly class LineBreak implements InlineElement {}
