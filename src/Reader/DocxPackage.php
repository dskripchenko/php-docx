<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Reader;

use Dskripchenko\PhpDocx\Exception\DocxException;

/**
 * An immutable container holding every part of a parsed DOCX ZIP.
 *
 * The lifecycle: it is created by DocxPackageReader and passed into the
 * Body/Styles/... readers. Once every read is done it is discarded.
 *
 * All of the XML documents are already parsed into DOMDocuments (in lenient
 * mode). The media files are raw bytes (ImageReader needs them when decoding).
 */
final readonly class DocxPackage
{
    /**
     * @param  string  $documentPartPath  The path of the main document
     *                                    (usually `word/document.xml`).
     * @param  \DOMDocument  $documentXml  The main body part.
     * @param  \DOMDocument|null  $stylesXml  word/styles.xml (when present).
     * @param  \DOMDocument|null  $numberingXml  word/numbering.xml (when present).
     * @param  \DOMDocument|null  $themeXml  word/theme/theme1.xml (when present).
     * @param  \DOMDocument|null  $settingsXml  word/settings.xml (when present).
     * @param  \DOMDocument|null  $footnotesXml  word/footnotes.xml (when present).
     * @param  array<string, \DOMDocument>  $headers  partPath → \DOMDocument
     *                                                for `word/header*.xml`.
     * @param  array<string, \DOMDocument>  $footers  partPath → \DOMDocument
     *                                                for `word/footer*.xml`.
     * @param  array<string, list<Relationship>>  $relationshipsByPart  partPath
     *                                                     → the list for that
     *                                                     part. The key is the
     *                                                     path of the part the
     *                                                     `.rels` file belongs
     *                                                     to (NOT the path of
     *                                                     the .rels itself).
     * @param  array<string, string>  $media  zip path → the binary bytes
     *                                        (for `word/media/*`).
     * @param  array<string, string>  $overrideContentTypes  partName → the mime
     *                                                       type (from an <Override>).
     * @param  array<string, string>  $defaultContentTypes  extension → the mime
     *                                                       type (from a <Default>).
     */
    public function __construct(
        public string $documentPartPath,
        public \DOMDocument $documentXml,
        public ?\DOMDocument $stylesXml = null,
        public ?\DOMDocument $numberingXml = null,
        public ?\DOMDocument $themeXml = null,
        public ?\DOMDocument $settingsXml = null,
        /** word/footnotes.xml — the footnote texts by their identifiers. */
        public ?\DOMDocument $footnotesXml = null,
        public array $headers = [],
        public array $footers = [],
        public array $relationshipsByPart = [],
        public array $media = [],
        public array $overrideContentTypes = [],
        public array $defaultContentTypes = [],
    ) {}

    /**
     * Every relationship declared for the main document
     * (`word/_rels/document.xml.rels`).
     *
     * @return list<Relationship>
     */
    public function documentRelationships(): array
    {
        return $this->relationshipsByPart[$this->documentPartPath] ?? [];
    }

    /**
     * Resolves the target of an image, a header and so on by its rId inside
     * document.xml.
     *
     * @throws DocxException when the rId is not registered.
     */
    public function resolveDocumentRel(string $rId): Relationship
    {
        return $this->resolveRel($this->documentPartPath, $rId);
    }

    /**
     * Resolves an rId inside an arbitrary part (a header or a footer may have
     * .rels of its own, with images).
     *
     * @throws DocxException when the rId is not registered for that part.
     */
    public function resolveRel(string $partPath, string $rId): Relationship
    {
        $rels = $this->relationshipsByPart[$partPath] ?? [];
        foreach ($rels as $rel) {
            if ($rel->id === $rId) {
                return $rel;
            }
        }
        throw new DocxException(sprintf(
            'Не найдена relationship %s для part %s.',
            $rId,
            $partPath,
        ));
    }

    /**
     * Resolves a relative target into an absolute zip path.
     *
     * For instance: target="media/image1.png" from the part
     * "word/document.xml" → "word/media/image1.png".
     */
    public function resolveMediaPath(string $partPath, string $relativeTarget): string
    {
        if (str_starts_with($relativeTarget, '/')) {
            return ltrim($relativeTarget, '/');
        }
        $base = $this->partDirectory($partPath);

        return $base === '' ? $relativeTarget : $base.'/'.$relativeTarget;
    }

    public function mediaBytes(string $zipPath): ?string
    {
        return $this->media[$zipPath] ?? null;
    }

    private function partDirectory(string $partPath): string
    {
        $pos = strrpos($partPath, '/');

        return $pos === false ? '' : substr($partPath, 0, $pos);
    }
}
