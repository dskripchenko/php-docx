<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Reader;

use Dskripchenko\PhpDocx\Exception\DocxException;

/**
 * Immutable container со всеми parts'ами разобранного DOCX-ZIP'а.
 *
 * Lifecycle: создаётся DocxPackageReader → передаётся в Body/Styles/...
 * reader'ы. После всех читаний — отбрасывается.
 *
 * Все XML-документы уже распарсены через DOMDocument (lenient mode).
 * Media-файлы — raw bytes (нужны ImageReader'у при decode'е).
 */
final readonly class DocxPackage
{
    /**
     * @param  string  $documentPartPath  Path к основному документу
     *                                    (обычно `word/document.xml`).
     * @param  \DOMDocument  $documentXml  Главный body part.
     * @param  \DOMDocument|null  $stylesXml  word/styles.xml (если есть).
     * @param  \DOMDocument|null  $numberingXml  word/numbering.xml (если есть).
     * @param  \DOMDocument|null  $themeXml  word/theme/theme1.xml (если есть).
     * @param  \DOMDocument|null  $settingsXml  word/settings.xml (если есть).
     * @param  array<string, \DOMDocument>  $headers  partPath → \DOMDocument
     *                                                для `word/header*.xml`.
     * @param  array<string, \DOMDocument>  $footers  partPath → \DOMDocument
     *                                                для `word/footer*.xml`.
     * @param  array<string, list<Relationship>>  $relationshipsByPart  partPath
     *                                                     → list для этого part'а.
     *                                                     Ключ — путь part'а
     *                                                     к которому относится
     *                                                     `.rels`-файл
     *                                                     (НЕ путь самого .rels).
     * @param  array<string, string>  $media  zip-path → binary bytes
     *                                        (для `word/media/*`).
     * @param  array<string, string>  $overrideContentTypes  partName → mime type
     *                                                       (из <Override>).
     * @param  array<string, string>  $defaultContentTypes  extension → mime type
     *                                                       (из <Default>).
     */
    public function __construct(
        public string $documentPartPath,
        public \DOMDocument $documentXml,
        public ?\DOMDocument $stylesXml = null,
        public ?\DOMDocument $numberingXml = null,
        public ?\DOMDocument $themeXml = null,
        public ?\DOMDocument $settingsXml = null,
        public array $headers = [],
        public array $footers = [],
        public array $relationshipsByPart = [],
        public array $media = [],
        public array $overrideContentTypes = [],
        public array $defaultContentTypes = [],
    ) {}

    /**
     * Все relationships, объявленные для главного документа
     * (`word/_rels/document.xml.rels`).
     *
     * @return list<Relationship>
     */
    public function documentRelationships(): array
    {
        return $this->relationshipsByPart[$this->documentPartPath] ?? [];
    }

    /**
     * Resolve target картинки/header'а/etc. по rId внутри document.xml.
     *
     * @throws DocxException если rId не зарегистрирован.
     */
    public function resolveDocumentRel(string $rId): Relationship
    {
        return $this->resolveRel($this->documentPartPath, $rId);
    }

    /**
     * Resolve rId внутри произвольного part'а (header/footer тоже могут
     * иметь свои .rels с картинками).
     *
     * @throws DocxException если rId не зарегистрирован для part'а.
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
     * Резолв относительного target'а к абсолютному zip-path.
     *
     * Пример: target="media/image1.png" из части "word/document.xml"
     *         → "word/media/image1.png".
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
