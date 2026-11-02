<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Element;

/**
 * A forced column break (`<w:p><w:r><w:br w:type="column"/></w:r></w:p>`): the
 * text after it starts at the top of the next column of the section (see
 * `Style\Columns`). In a section with a single column Word treats it as a
 * page break.
 */
final readonly class ColumnBreak implements BlockElement {}
