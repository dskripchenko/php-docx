<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Reader;

/**
 * Relationship из `*.rels`-файла. Зеркало структуры, которую регистрирует
 * Writer\RelationshipManager.
 *
 * Tip: Type-константы для удобства матчинга — те же URI что в writer'е,
 * скопированы здесь чтобы Reader был автономным.
 */
final readonly class Relationship
{
    public const string TYPE_OFFICE_DOCUMENT = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument';

    public const string TYPE_IMAGE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/image';

    public const string TYPE_HYPERLINK = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink';

    public const string TYPE_HEADER = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/header';

    public const string TYPE_FOOTER = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer';

    public const string TYPE_NUMBERING = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/numbering';

    public const string TYPE_STYLES = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles';

    public const string TYPE_THEME = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme';

    public const string TYPE_FONT_TABLE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/fontTable';

    public const string TYPE_SETTINGS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/settings';

    public const string TYPE_FOOTNOTES = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/footnotes';

    public const string TYPE_ENDNOTES = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/endnotes';

    /**
     * @param  string  $id  Relationship ID (rId1, rId2, ...).
     * @param  string  $type  URI типа (см. TYPE_*-константы).
     * @param  string  $target  Путь относительно part'а, который владеет
     *                          этим .rels-файлом. Для external — абсолютный URL.
     * @param  string|null  $targetMode  `External` для гиперссылок; null = internal.
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
