<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Writer;

use Dskripchenko\PhpDocx\Element\Image;
use Dskripchenko\PhpDocx\Element\ImageFormat;

/**
 * Регистратор relationships документа. Используется для image-embed
 * (Phase 4), hyperlink-rels (Phase 4), header/footer-rels (Phase 5a).
 *
 * Хранит:
 *  - сами `<Relationship>` записи для `word/_rels/document.xml.rels`
 *  - media-файлы для `word/media/imageN.ext`
 *  - extensions для `[Content_Types].xml` (Default-entries)
 *  - hyperlink targets для внешних ссылок
 *
 * rId генерится монотонно (rId1, rId2, ...).
 */
final class RelationshipManager
{
    private int $nextRId = 1;

    /** @var list<array{id: string, type: string, target: string, targetMode?: string}> */
    private array $relationships = [];

    /** @var array<string, string>  filename → binary contents (для word/media/) */
    private array $mediaFiles = [];

    /** @var array<string, string>  extension → content-type (для Content_Types.xml Default) */
    private array $contentTypeExtensions = [];

    public const string TYPE_IMAGE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/image';

    public const string TYPE_HYPERLINK = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink';

    public const string TYPE_HEADER = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/header';

    public const string TYPE_FOOTER = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer';

    public const string TYPE_NUMBERING = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/numbering';

    public const string TYPE_STYLES = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles';

    public const string TYPE_SETTINGS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/settings';

    /**
     * Регистрирует image и возвращает rId для использования в `<a:blip r:embed="..."/>`.
     */
    public function registerImage(Image $image): string
    {
        $rId = $this->nextRId();
        $imageIndex = count(array_filter(
            $this->mediaFiles,
            static fn (string $name): bool => str_contains($name, 'image'),
            ARRAY_FILTER_USE_KEY,
        )) + 1;
        $filename = sprintf('image%d.%s', $imageIndex, $image->format->extension());

        $this->mediaFiles['word/media/'.$filename] = $image->binary;
        $this->contentTypeExtensions[$image->format->extension()] = $image->format->mimeType();

        $this->relationships[] = [
            'id' => $rId,
            'type' => self::TYPE_IMAGE,
            'target' => 'media/'.$filename,
        ];

        return $rId;
    }

    /**
     * Регистрирует внешний URL (hyperlink) и возвращает rId.
     */
    public function registerHyperlink(string $href): string
    {
        $rId = $this->nextRId();
        $this->relationships[] = [
            'id' => $rId,
            'type' => self::TYPE_HYPERLINK,
            'target' => $href,
            'targetMode' => 'External',
        ];

        return $rId;
    }

    /**
     * Регистрирует header/footer relationship (header1.xml / footer1.xml).
     */
    public function registerHeaderFooter(string $target, bool $isHeader): string
    {
        $rId = $this->nextRId();
        $this->relationships[] = [
            'id' => $rId,
            'type' => $isHeader ? self::TYPE_HEADER : self::TYPE_FOOTER,
            'target' => $target,
        ];

        return $rId;
    }

    public function registerStyles(): string
    {
        $rId = $this->nextRId();
        $this->relationships[] = [
            'id' => $rId,
            'type' => self::TYPE_STYLES,
            'target' => 'styles.xml',
        ];

        return $rId;
    }

    public function registerNumbering(): string
    {
        $rId = $this->nextRId();
        $this->relationships[] = [
            'id' => $rId,
            'type' => self::TYPE_NUMBERING,
            'target' => 'numbering.xml',
        ];

        return $rId;
    }

    public function registerSettings(): string
    {
        $rId = $this->nextRId();
        $this->relationships[] = [
            'id' => $rId,
            'type' => self::TYPE_SETTINGS,
            'target' => 'settings.xml',
        ];

        return $rId;
    }

    /**
     * @return list<array{id: string, type: string, target: string, targetMode?: string}>
     */
    public function relationships(): array
    {
        return $this->relationships;
    }

    /**
     * @return array<string, string>  zip-path → binary contents
     */
    public function mediaFiles(): array
    {
        return $this->mediaFiles;
    }

    /**
     * @return array<string, string>  extension → mime
     */
    public function contentTypeExtensions(): array
    {
        return $this->contentTypeExtensions;
    }

    private function nextRId(): string
    {
        return 'rId'.$this->nextRId++;
    }
}
