<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Reader;

use Dskripchenko\PhpDocx\Exception\DocxException;

/**
 * Phase 1 — DocxPackageReader.
 *
 * Распаковывает DOCX bytes (ZIP) → DocxPackage VO.
 *
 * Этапы:
 *  1. ZipArchive::open из temp-файла (PHP не имеет stream-API для ZIP'ов из памяти).
 *  2. Parse `[Content_Types].xml` → defaults + overrides.
 *  3. Parse `_rels/.rels` (root) → найти main document part (officeDocument rel).
 *  4. Parse `word/_rels/document.xml.rels` → relationships документа.
 *  5. По overrides + rels — collect styles/numbering/theme/settings/headers/footers.
 *  6. Для каждого header/footer part'а: load rels (если есть) — там сидят
 *     картинки header'а.
 *  7. Все `word/media/*` — bytes в память.
 *
 * Lenient mode: малое отсутствие частей (styles.xml, theme.xml) — не ошибка
 * (минимальный DOCX может не иметь их). Отсутствие main document — ошибка.
 */
final class DocxPackageReader
{
    /**
     * Открывает DOCX из binary string.
     *
     * @throws DocxException на malformed ZIP или отсутствие main document part.
     */
    public function read(string $bytes): DocxPackage
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'docx-read-');
        if ($tmpFile === false) {
            throw new DocxException('Не удалось создать temp-файл для чтения DOCX.');
        }
        if (file_put_contents($tmpFile, $bytes) === false) {
            @unlink($tmpFile);
            throw new DocxException('Не удалось записать DOCX-bytes в temp-файл.');
        }

        try {
            return $this->readFromFile($tmpFile);
        } finally {
            @unlink($tmpFile);
        }
    }

    /**
     * @throws DocxException
     */
    public function readFromFile(string $zipPath): DocxPackage
    {
        $zip = new \ZipArchive;
        $openResult = $zip->open($zipPath, \ZipArchive::RDONLY);
        if ($openResult !== true) {
            throw new DocxException(sprintf(
                'Не удалось открыть ZIP-архив (code %d).',
                (int) $openResult,
            ));
        }

        try {
            return $this->readFromArchive($zip);
        } finally {
            $zip->close();
        }
    }

    /**
     * @throws DocxException
     */
    private function readFromArchive(\ZipArchive $zip): DocxPackage
    {
        // 1. Content_Types.xml — обязательный
        $contentTypesXml = $this->readEntry($zip, '[Content_Types].xml', required: true);
        [$defaults, $overrides] = $this->parseContentTypes($contentTypesXml);

        // 2. Root rels — обязательный (нужен для нахождения main document)
        $rootRelsXml = $this->readEntry($zip, '_rels/.rels', required: true);
        $rootRels = $this->parseRels($rootRelsXml);

        $documentPartPath = $this->findMainDocumentPath($rootRels);
        if ($documentPartPath === null) {
            throw new DocxException('В _rels/.rels отсутствует relationship на officeDocument.');
        }

        // 3. Document.xml + его rels
        $documentXml = $this->loadXml(
            $this->readEntry($zip, $documentPartPath, required: true),
        );
        $documentRels = $this->loadPartRels($zip, $documentPartPath);

        $relationshipsByPart = [$documentPartPath => $documentRels];

        // 4. Discover sibling parts через documentRels.
        $stylesXml = null;
        $numberingXml = null;
        $themeXml = null;
        $settingsXml = null;
        $footnotesXml = null;
        $headers = [];
        $footers = [];

        foreach ($documentRels as $rel) {
            if ($rel->isExternal()) {
                continue;
            }
            $absPath = $this->resolvePath($documentPartPath, $rel->target);
            $entryBytes = $this->readEntry($zip, $absPath, required: false);
            if ($entryBytes === null) {
                continue;
            }
            match ($rel->type) {
                Relationship::TYPE_STYLES => $stylesXml = $this->loadXml($entryBytes),
                Relationship::TYPE_NUMBERING => $numberingXml = $this->loadXml($entryBytes),
                Relationship::TYPE_THEME => $themeXml = $this->loadXml($entryBytes),
                Relationship::TYPE_SETTINGS => $settingsXml = $this->loadXml($entryBytes),
                Relationship::TYPE_FOOTNOTES => $footnotesXml = $this->loadXml($entryBytes),
                Relationship::TYPE_HEADER => $headers[$absPath] = $this->loadXml($entryBytes),
                Relationship::TYPE_FOOTER => $footers[$absPath] = $this->loadXml($entryBytes),
                default => null,
            };
            // header/footer могут иметь свои rels (для картинок в шапке)
            if ($rel->type === Relationship::TYPE_HEADER || $rel->type === Relationship::TYPE_FOOTER) {
                $partRels = $this->loadPartRels($zip, $absPath);
                if ($partRels !== []) {
                    $relationshipsByPart[$absPath] = $partRels;
                }
            }
        }

        // 5. Media — все word/media/* в bytes
        $media = $this->collectMedia($zip);

        return new DocxPackage(
            documentPartPath: $documentPartPath,
            documentXml: $documentXml,
            stylesXml: $stylesXml,
            numberingXml: $numberingXml,
            themeXml: $themeXml,
            settingsXml: $settingsXml,
            footnotesXml: $footnotesXml,
            headers: $headers,
            footers: $footers,
            relationshipsByPart: $relationshipsByPart,
            media: $media,
            overrideContentTypes: $overrides,
            defaultContentTypes: $defaults,
        );
    }

    /**
     * Читает entry из ZIP. Возвращает null если required=false и entry нет.
     *
     * @throws DocxException если required=true и entry отсутствует.
     */
    /**
     * @phpstan-return ($required is true ? string : ?string)
     */
    private function readEntry(\ZipArchive $zip, string $entryName, bool $required): ?string
    {
        $bytes = $zip->getFromName($entryName);
        if ($bytes === false) {
            if ($required) {
                throw new DocxException(sprintf('В DOCX отсутствует обязательный entry: %s', $entryName));
            }

            return null;
        }

        return $bytes;
    }

    /**
     * Парсит `[Content_Types].xml`:
     *  - `<Default Extension="png" ContentType="image/png"/>` → defaults['png']='image/png'
     *  - `<Override PartName="/word/document.xml" ContentType="..."/>` → overrides['word/document.xml']='...'
     *
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    private function parseContentTypes(string $xml): array
    {
        $doc = $this->loadXml($xml);
        $defaults = [];
        $overrides = [];

        foreach ($doc->getElementsByTagName('Default') as $el) {
            if (! $el instanceof \DOMElement) {
                continue;
            }
            $ext = strtolower($el->getAttribute('Extension'));
            $mime = $el->getAttribute('ContentType');
            if ($ext !== '' && $mime !== '') {
                $defaults[$ext] = $mime;
            }
        }
        foreach ($doc->getElementsByTagName('Override') as $el) {
            if (! $el instanceof \DOMElement) {
                continue;
            }
            $part = ltrim($el->getAttribute('PartName'), '/');
            $mime = $el->getAttribute('ContentType');
            if ($part !== '' && $mime !== '') {
                $overrides[$part] = $mime;
            }
        }

        return [$defaults, $overrides];
    }

    /**
     * Парсит `.rels`-XML → list<Relationship>.
     *
     * @return list<Relationship>
     */
    private function parseRels(string $xml): array
    {
        $doc = $this->loadXml($xml);
        $rels = [];
        foreach ($doc->getElementsByTagName('Relationship') as $el) {
            if (! $el instanceof \DOMElement) {
                continue;
            }
            $id = $el->getAttribute('Id');
            $type = $el->getAttribute('Type');
            $target = $el->getAttribute('Target');
            if ($id === '' || $type === '' || $target === '') {
                continue;
            }
            $mode = $el->hasAttribute('TargetMode') ? $el->getAttribute('TargetMode') : null;
            $rels[] = new Relationship($id, $type, $target, $mode);
        }

        return $rels;
    }

    /**
     * @param  list<Relationship>  $rootRels
     */
    private function findMainDocumentPath(array $rootRels): ?string
    {
        foreach ($rootRels as $rel) {
            if ($rel->type === Relationship::TYPE_OFFICE_DOCUMENT) {
                // Root rels — relative к корню ZIP'а.
                return ltrim($rel->target, '/');
            }
        }

        return null;
    }

    /**
     * Загружает `.rels`-файл рядом с part'ом, если существует.
     * Например для `word/document.xml` ищет `word/_rels/document.xml.rels`.
     *
     * @return list<Relationship>
     */
    private function loadPartRels(\ZipArchive $zip, string $partPath): array
    {
        $relsPath = $this->relsPathFor($partPath);
        $bytes = $this->readEntry($zip, $relsPath, required: false);
        if ($bytes === null) {
            return [];
        }

        return $this->parseRels($bytes);
    }

    /**
     * Преобразует `word/document.xml` → `word/_rels/document.xml.rels`.
     */
    private function relsPathFor(string $partPath): string
    {
        $pos = strrpos($partPath, '/');
        if ($pos === false) {
            return '_rels/'.$partPath.'.rels';
        }
        $dir = substr($partPath, 0, $pos);
        $file = substr($partPath, $pos + 1);

        return $dir.'/_rels/'.$file.'.rels';
    }

    /**
     * Резолв относительного target'а из rels к абсолютному zip-path.
     *
     * Target в .rels всегда относительный к директории владельца:
     *  base="word/document.xml" + target="styles.xml" → "word/styles.xml"
     *  base="word/document.xml" + target="media/img1.png" → "word/media/img1.png"
     *  target="../customXml/item1.xml" → разрешаем `..`
     */
    private function resolvePath(string $basePart, string $target): string
    {
        if (str_starts_with($target, '/')) {
            return ltrim($target, '/');
        }
        $basePos = strrpos($basePart, '/');
        $baseDir = $basePos === false ? '' : substr($basePart, 0, $basePos);

        $segments = $baseDir === '' ? [] : explode('/', $baseDir);
        foreach (explode('/', $target) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($segments);

                continue;
            }
            $segments[] = $seg;
        }

        return implode('/', $segments);
    }

    /**
     * Собирает все entries по префиксу `word/media/`.
     *
     * @return array<string, string>
     */
    private function collectMedia(\ZipArchive $zip): array
    {
        $media = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false) {
                continue;
            }
            if (! str_starts_with($name, 'word/media/')) {
                continue;
            }
            $bytes = $zip->getFromIndex($i);
            if ($bytes === false) {
                continue;
            }
            $media[$name] = $bytes;
        }

        return $media;
    }

    /**
     * Загружает XML-строку в DOMDocument с подавлением warning'ов
     * (некоторые DOCX от Word имеют BOM/whitespace, что libxml ругается).
     *
     * @throws DocxException если XML invalid.
     */
    private function loadXml(string $xml): \DOMDocument
    {
        $doc = new \DOMDocument;
        $doc->preserveWhiteSpace = true;
        $doc->formatOutput = false;

        $prev = libxml_use_internal_errors(true);
        $loaded = $doc->loadXML($xml, LIBXML_NOENT | LIBXML_PARSEHUGE);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (! $loaded) {
            throw new DocxException('Не удалось распарсить XML внутри DOCX-archive.');
        }

        return $doc;
    }
}
