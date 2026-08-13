<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Element;

/**
 * A forced page break (`<w:p><w:r><w:br w:type="page"/></w:r></w:p>`).
 */
final readonly class PageBreak implements BlockElement {}
