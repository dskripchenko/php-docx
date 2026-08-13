<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Reader;

use Dskripchenko\PhpDocx\Exception\DocxException;

/**
 * Phase 1 — DocxPackageReader.
 *
 * Unpacks the DOCX bytes (a ZIP) into a DocxPackage value object.
 *
 * The stages:
 *  1. ZipArchive::open from a temporary file (PHP has no stream API for ZIPs in
 *     memory).
 *  2. Parse `[Content_Types].xml` → the defaults plus the overrides.
 *  3. Parse `_rels/.rels` (the root one) → find the main document part (the
 *     officeDocument relationship).
 *  4. Parse `word/_rels/document.xml.rels` → the document's relationships.
 *  5. From the overrides plus the relationships, collect
 *     styles/numbering/theme/settings/headers/footers.
 *  6. For every header and footer part: load its relationships (when there are
 *     any) — the header's images live there.
 *  7. Every `word/media/*` goes into memory as bytes.
 *
 * Lenient mode: a few missing parts (styles.xml, theme.xml) are not an error (a
 * minimal DOCX may lack them). A missing main document is an error.
 */
final class DocxPackageReader
{
    /**
     * Opens a DOCX from a binary string.
     *
     * @throws DocxException on a malformed ZIP or a missing main document part.
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
        // 1. Content_Types.xml — mandatory
        $contentTypesXml = $this->readEntry($zip, '[Content_Types].xml', required: true);
        [$defaults, $overrides] = $this->parseContentTypes($contentTypesXml);

        // 2. The root rels — mandatory (it is how the main document is found)
        $rootRelsXml = $this->readEntry($zip, '_rels/.rels', required: true);
        $rootRels = $this->parseRels($rootRelsXml);

        $documentPartPath = $this->findMainDocumentPath($rootRels);
        if ($documentPartPath === null) {
            throw new DocxException('В _rels/.rels отсутствует relationship на officeDocument.');
        }

        // 3. document.xml plus its rels
        $documentXml = $this->loadXml(
            $this->readEntry($zip, $documentPartPath, required: true),
        );
        $documentRels = $this->loadPartRels($zip, $documentPartPath);

        $relationshipsByPart = [$documentPartPath => $documentRels];

        // 4. Discover the sibling parts through documentRels.
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
            // a header or a footer may have rels of its own (for the images in it)
            if ($rel->type === Relationship::TYPE_HEADER || $rel->type === Relationship::TYPE_FOOTER) {
                $partRels = $this->loadPartRels($zip, $absPath);
                if ($partRels !== []) {
                    $relationshipsByPart[$absPath] = $partRels;
                }
            }
        }

        // 5. The media — every word/media/* as bytes
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
     * Reads an entry from the ZIP. Returns null when required=false and the
     * entry is absent.
     *
     * @throws DocxException when required=true and the entry is absent.
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
     * Parses `[Content_Types].xml`:
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
     * Parses the `.rels` XML into a list<Relationship>.
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
                // The root rels are relative to the ZIP's root.
                return ltrim($rel->target, '/');
            }
        }

        return null;
    }

    /**
     * Loads the `.rels` file next to a part, when one exists. For
     * `word/document.xml`, for instance, it looks for
     * `word/_rels/document.xml.rels`.
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
     * Turns `word/document.xml` into `word/_rels/document.xml.rels`.
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
     * Resolves a relative target from the rels into an absolute zip path.
     *
     * A target in a .rels file is always relative to its owner's directory:
     *  base="word/document.xml" + target="styles.xml" → "word/styles.xml"
     *  base="word/document.xml" + target="media/img1.png" → "word/media/img1.png"
     *  target="../customXml/item1.xml" → the `..` is resolved
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
     * Gathers every entry under the `word/media/` prefix.
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
     * Loads an XML string into a DOMDocument with the warnings suppressed (some
     * DOCX files from Word carry a BOM or whitespace that libxml complains
     * about).
     *
     * @throws DocxException when the XML is invalid.
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
