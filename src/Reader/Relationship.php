<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Reader;

/**
 * A relationship from a `*.rels` file. A mirror of the structure that
 * Writer\RelationshipManager registers.
 *
 * The TYPE_* constants are there for convenient matching — the same URIs as in
 * the writer, copied here to keep the reader self-contained.
 */
final readonly class Relationship
{
    public const TYPE_OFFICE_DOCUMENT = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument';

    public const TYPE_IMAGE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/image';

    public const TYPE_HYPERLINK = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink';

    public const TYPE_HEADER = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/header';

    public const TYPE_FOOTER = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer';

    public const TYPE_NUMBERING = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/numbering';

    public const TYPE_STYLES = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles';

    public const TYPE_THEME = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme';

    public const TYPE_FONT_TABLE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/fontTable';

    public const TYPE_SETTINGS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/settings';

    public const TYPE_FOOTNOTES = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/footnotes';

    public const TYPE_ENDNOTES = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/endnotes';

    /**
     * @param  string  $id  Relationship ID (rId1, rId2, ...).
     * @param  string  $type  The type URI (see the TYPE_* constants).
     * @param  string  $target  A path relative to the part that owns this .rels
     *                          file. For an external one, an absolute URL.
     * @param  string|null  $targetMode  `External` for hyperlinks; null means
     *                                   internal.
     */
    public function __construct(
        public string $id,
        public string $type,
        public string $target,
        public ?string $targetMode = null,
    ) {}

    public function isExternal(): bool
    {
        return $this->targetMode === 'External';
    }
}
