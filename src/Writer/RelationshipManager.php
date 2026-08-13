<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Writer;

use Dskripchenko\PhpDocx\Element\Image;
use Dskripchenko\PhpDocx\Element\ImageFormat;

/**
 * Registry of the document's relationships. Used for image embedding
 * (Phase 4), hyperlink rels (Phase 4), header/footer rels (Phase 5a).
 *
 * Keeps:
 *  - the `<Relationship>` entries themselves for `word/_rels/document.xml.rels`
 *  - media files for `word/media/imageN.ext`
 *  - extensions for `[Content_Types].xml` (Default entries)
 *  - hyperlink targets for external links
 *
 * The rId is generated monotonically (rId1, rId2, ...).
 */
final class RelationshipManager
{
    private int $nextRId = 1;

    private int $nextDrawingId = 1;

    /** @var list<array{id: string, type: string, target: string, targetMode?: string}> */
    private array $relationships = [];

    /**
     * The current document part: null is `document.xml` itself.
     *
     * An `r:embed` reference resolves against the rels of THE PART where it is
     * written: `header1.xml` looks into `word/_rels/header1.xml.rels`. While
     * header images were registered in the document rels, Word did not find
     * them and declared the file corrupt.
     */
    private ?string $part = null;

    /** @var array<string, list<array{id: string, type: string, target: string, targetMode?: string}>> */
    private array $partRelationships = [];

    /** @var array<string, int> */
    private array $partNextRId = [];

    /** @var array<string, string>  filename → binary contents (for word/media/) */
    private array $mediaFiles = [];

    /** @var array<string, string>  extension → content-type (for Content_Types.xml Default) */
    private array $contentTypeExtensions = [];

    public const TYPE_IMAGE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/image';

    public const TYPE_HYPERLINK = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink';

    public const TYPE_HEADER = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/header';

    public const TYPE_FOOTER = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer';

    public const TYPE_NUMBERING = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/numbering';

    public const TYPE_STYLES = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles';

    public const TYPE_SETTINGS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/settings';

    /**
     * Document-wide sequential number of a graphic object for `wp:docPr`.
     *
     * The identifier has to be unique across the whole document: Word declares
     * the file corrupt when two drawings carry the same number. It cannot be
     * derived from the relationship number — ever since each part numbers its
     * own relationships, a header image and the first body image both got
     * `rId1`, and therefore the same number.
     */
    public function nextDrawingId(): int
    {
        return $this->nextDrawingId++;
    }

    /**
     * Renders a document part so that its relationships land in its own rels
     * file rather than in the shared document one.
     *
     * Media files stay shared: `word/media` belongs to the package, not to a
     * part.
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
     * Relationships of the document parts: part name → its relationships.
     *
     * @return array<string, list<array{id: string, type: string, target: string, targetMode?: string}>>
     */
    public function partRelationships(): array
    {
        return array_filter($this->partRelationships, static fn (array $rels): bool => $rels !== []);
    }

    /**
     * Registers an image and returns the rId to use in `<a:blip r:embed="..."/>`.
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
     * Registers an external URL (hyperlink) and returns its rId.
     */
    public function registerHyperlink(string $href): string
    {
        return $this->add(self::TYPE_HYPERLINK, $href, targetMode: 'External');
    }

    /**
     * Registers a header/footer relationship (header1.xml / footer1.xml).
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

    /** Writes the relationship into the current part and returns its rId. */
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

    /** Each part numbers its relationships on its own: they resolve part-locally. */
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
