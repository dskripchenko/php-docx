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

    /**
     * Текущая часть документа: null — сам `document.xml`.
     *
     * Ссылка `r:embed` разрешается относительно rels ТОЙ ЧАСТИ, где она
     * написана: `header1.xml` смотрит в `word/_rels/header1.xml.rels`.
     * Пока картинки колонтитула регистрировались в rels документа, Word
     * не находил их и объявлял файл повреждённым.
     */
    private ?string $part = null;

    /** @var array<string, list<array{id: string, type: string, target: string, targetMode?: string}>> */
    private array $partRelationships = [];

    /** @var array<string, int> */
    private array $partNextRId = [];

    /** @var array<string, string>  filename → binary contents (для word/media/) */
    private array $mediaFiles = [];

    /** @var array<string, string>  extension → content-type (для Content_Types.xml Default) */
    private array $contentTypeExtensions = [];

    public const TYPE_IMAGE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/image';

    public const TYPE_HYPERLINK = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink';

    public const TYPE_HEADER = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/header';

    public const TYPE_FOOTER = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer';

    public const TYPE_NUMBERING = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/numbering';

    public const TYPE_STYLES = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles';

    public const TYPE_SETTINGS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/settings';

    /**
     * Выполняет отрисовку части документа так, чтобы её ссылки попали в её
     * собственный rels-файл, а не в общий документный.
     *
     * Медиа-файлы остаются общими: `word/media` — папка пакета, а не части.
     *
     * @template T
     *
     * @param  \Closure(): T  $render
     * @return T
     */
    public function forPart(string $part, \Closure $render): mixed
    {
        $previous = $this->part;
        $this->part = $part;
        try {
            return $render();
        } finally {
            $this->part = $previous;
        }
    }

    /**
     * Ссылки частей документа: имя части → её relationships.
     *
     * @return array<string, list<array{id: string, type: string, target: string, targetMode?: string}>>
     */
    public function partRelationships(): array
    {
        return array_filter($this->partRelationships, static fn (array $rels): bool => $rels !== []);
    }

    /**
     * Регистрирует image и возвращает rId для использования в `<a:blip r:embed="..."/>`.
     */
    public function registerImage(Image $image): string
    {
        $imageIndex = count(array_filter(
            $this->mediaFiles,
            static fn (string $name): bool => str_contains($name, 'image'),
            ARRAY_FILTER_USE_KEY,
        )) + 1;
        $filename = sprintf('image%d.%s', $imageIndex, $image->format->extension());

        $this->mediaFiles['word/media/'.$filename] = $image->binary;
        $this->contentTypeExtensions[$image->format->extension()] = $image->format->mimeType();

        return $this->add(self::TYPE_IMAGE, 'media/'.$filename);
    }

    /**
     * Регистрирует внешний URL (hyperlink) и возвращает rId.
     */
    public function registerHyperlink(string $href): string
    {
        return $this->add(self::TYPE_HYPERLINK, $href, targetMode: 'External');
    }

    /**
     * Регистрирует header/footer relationship (header1.xml / footer1.xml).
     */
    public function registerHeaderFooter(string $target, bool $isHeader): string
    {
        return $this->add($isHeader ? self::TYPE_HEADER : self::TYPE_FOOTER, $target);
    }

    public function registerStyles(): string
    {
        return $this->add(self::TYPE_STYLES, 'styles.xml');
    }

    public function registerNumbering(): string
    {
        return $this->add(self::TYPE_NUMBERING, 'numbering.xml');
    }

    public function registerSettings(): string
    {
        return $this->add(self::TYPE_SETTINGS, 'settings.xml');
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

    /** Записывает ссылку в текущую часть и возвращает её rId. */
    private function add(string $type, string $target, ?string $targetMode = null): string
    {
        $rel = ['id' => $this->nextRId(), 'type' => $type, 'target' => $target];
        if ($targetMode !== null) {
            $rel['targetMode'] = $targetMode;
        }

        if ($this->part === null) {
            $this->relationships[] = $rel;
        } else {
            $this->partRelationships[$this->part][] = $rel;
        }

        return $rel['id'];
    }

    /** Нумерация ссылок своя у каждой части: она разрешается внутри части. */
    private function nextRId(): string
    {
        if ($this->part === null) {
            return 'rId'.$this->nextRId++;
        }

        $next = ($this->partNextRId[$this->part] ?? 0) + 1;
        $this->partNextRId[$this->part] = $next;

        return 'rId'.$next;
    }
}
