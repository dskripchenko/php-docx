<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Reader;

/**
 * Phase 9 — VariableDetector.
 *
 * Ищет в `DocxPackage` (body + headers + footers) три источника
 * переменных:
 *
 *  1. **MERGEFIELD** — `<w:fldSimple w:instr="MERGEFIELD CustomerName \\* MERGEFORMAT"/>`
 *     или complex field через `<w:fldChar>` + `<w:instrText>` со склейкой
 *     по runs.
 *  2. **SDT content controls** — `<w:sdt>` с `<w:sdtPr><w:tag w:val="X"/></w:sdtPr>`.
 *     Если есть `<w:alias>` — используем как display label.
 *     Sample-value = плейн-текст внутри `<w:sdtContent>` (placeholder Word'а).
 *  3. **Text patterns** — на тексте после склейки соседних `<w:t>` runs
 *     внутри одного параграфа. По умолчанию три regex'а: `{{var}}`, `${var}`,
 *     `%var%`. Caller может передать кастомные паттерны.
 *
 * Дедупликация: одинаковые (name, source) → возвращается одна запись.
 */
final class VariableDetector
{
    /** @var list<string> */
    private array $textPatterns;

    /**
     * @param  list<string>|null  $textPatterns  PHP-regex'ы с group(1) =
     *                                           имя переменной. Если null —
     *                                           defaults.
     */
    public function __construct(?array $textPatterns = null)
    {
        $this->textPatterns = $textPatterns ?? [
            '/\{\{\s*([a-zA-Z_][a-zA-Z0-9_\.]*)\s*\}\}/',
            '/\$\{\s*([a-zA-Z_][a-zA-Z0-9_\.]*)\s*\}/',
            '/%([a-zA-Z_][a-zA-Z0-9_\.]*)%/',
        ];
    }

    /**
     * @return list<DetectedVariable>
     */
    public function detect(DocxPackage $package): array
    {
        $found = [];
        $seen = []; // dedup key "source:name"
        $emit = function (DetectedVariable $v) use (&$found, &$seen): void {
            $key = $v->source->value.':'.$v->name;
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $found[] = $v;
        };

        foreach ($this->allDocuments($package) as $doc) {
            $this->detectInDocument($doc, $emit);
        }

        return $found;
    }

    /**
     * @return iterable<\DOMDocument>
     */
    private function allDocuments(DocxPackage $pkg): iterable
    {
        yield $pkg->documentXml;
        foreach ($pkg->headers as $doc) {
            yield $doc;
        }
        foreach ($pkg->footers as $doc) {
            yield $doc;
        }
    }

    private function detectInDocument(\DOMDocument $doc, \Closure $emit): void
    {
        $this->detectFldSimpleMergefields($doc, $emit);
        $this->detectComplexMergefields($doc, $emit);
        $this->detectSdtControls($doc, $emit);
        $this->detectTextPatterns($doc, $emit);
    }

    private function detectFldSimpleMergefields(\DOMDocument $doc, \Closure $emit): void
    {
        foreach ($doc->getElementsByTagNameNS(OoxmlNs::W, 'fldSimple') as $el) {
            if (! $el instanceof \DOMElement) {
                continue;
            }
            $instr = trim($el->getAttributeNS(OoxmlNs::W, 'instr'));
            $name = $this->extractMergefieldName($instr);
            if ($name === null) {
                continue;
            }
            $emit(new DetectedVariable(
                name: $name,
                source: VariableSource::MergeField,
                placeholder: $instr,
                sampleValue: $this->extractInnerText($el) ?: null,
            ));
        }
    }

    private function detectComplexMergefields(\DOMDocument $doc, \Closure $emit): void
    {
        // Сканируем все параграфы; внутри каждого state machine по runs.
        foreach ($doc->getElementsByTagNameNS(OoxmlNs::W, 'p') as $p) {
            if (! $p instanceof \DOMElement) {
                continue;
            }
            $state = null; // null | 'instr' | 'value'
            $instr = '';
            $value = '';

            foreach ($p->getElementsByTagNameNS(OoxmlNs::W, 'r') as $r) {
                if (! $r instanceof \DOMElement) {
                    continue;
                }
                foreach ($r->childNodes as $child) {
                    if (! $child instanceof \DOMElement || $child->namespaceURI !== OoxmlNs::W) {
                        continue;
                    }
                    switch ($child->localName) {
                        case 'fldChar':
                            $type = $child->getAttributeNS(OoxmlNs::W, 'fldCharType');
                            if ($type === 'begin') {
                                $state = 'instr';
                                $instr = '';
                                $value = '';
                            } elseif ($type === 'separate') {
                                $state = 'value';
                            } elseif ($type === 'end') {
                                $cleanInstr = trim($instr);
                                $name = $this->extractMergefieldName($cleanInstr);
                                if ($name !== null) {
                                    $emit(new DetectedVariable(
                                        name: $name,
                                        source: VariableSource::MergeField,
                                        placeholder: $cleanInstr,
                                        sampleValue: trim($value) ?: null,
                                    ));
                                }
                                $state = null;
                                $instr = '';
                                $value = '';
                            }
                            break;
                        case 'instrText':
                            if ($state === 'instr') {
                                $instr .= $child->textContent;
                            }
                            break;
                        case 't':
                            if ($state === 'value') {
                                $value .= $child->textContent;
                            }
                            break;
                    }
                }
            }
        }
    }

    private function detectSdtControls(\DOMDocument $doc, \Closure $emit): void
    {
        foreach ($doc->getElementsByTagNameNS(OoxmlNs::W, 'sdt') as $sdt) {
            if (! $sdt instanceof \DOMElement) {
                continue;
            }
            // Skip watermark-SDT'ы — у них tag/alias содержит "watermark".
            $sdtPr = OoxmlNs::firstChild($sdt, OoxmlNs::W, 'sdtPr');
            if ($sdtPr === null) {
                continue;
            }
            $tagEl = OoxmlNs::firstChild($sdtPr, OoxmlNs::W, 'tag');
            $aliasEl = OoxmlNs::firstChild($sdtPr, OoxmlNs::W, 'alias');
            $tag = $tagEl !== null ? OoxmlNs::wVal($tagEl) : null;
            $alias = $aliasEl !== null ? OoxmlNs::wVal($aliasEl) : null;

            // Name приоритет: tag > alias.
            $name = $this->sanitizeVarName($tag !== null && $tag !== '' ? $tag : ($alias ?? ''));
            if ($name === '') {
                continue;
            }
            if (str_contains(strtolower($name), 'watermark')) {
                continue;
            }

            $content = OoxmlNs::firstChild($sdt, OoxmlNs::W, 'sdtContent');
            $sample = $content !== null ? $this->extractInnerText($content) : null;

            $emit(new DetectedVariable(
                name: $name,
                source: VariableSource::ContentControl,
                placeholder: $tag !== null && $tag !== '' ? $tag : $alias ?? '',
                sampleValue: $sample !== '' ? $sample : null,
            ));
        }
    }

    private function detectTextPatterns(\DOMDocument $doc, \Closure $emit): void
    {
        // Склеиваем все <w:t> внутри одного параграфа в одну строку, потом
        // прогоняем regex'ы.
        foreach ($doc->getElementsByTagNameNS(OoxmlNs::W, 'p') as $p) {
            if (! $p instanceof \DOMElement) {
                continue;
            }
            $text = '';
            foreach ($p->getElementsByTagNameNS(OoxmlNs::W, 't') as $t) {
                if ($t instanceof \DOMElement) {
                    $text .= $t->textContent;
                }
            }
            if ($text === '') {
                continue;
            }
            foreach ($this->textPatterns as $pattern) {
                if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER) === 0) {
                    continue;
                }
                foreach ($matches as $m) {
                    if (! isset($m[1])) {
                        continue;
                    }
                    $name = trim($m[1]);
                    if ($name === '') {
                        continue;
                    }
                    $emit(new DetectedVariable(
                        name: $name,
                        source: VariableSource::TextPattern,
                        placeholder: $m[0],
                        sampleValue: null,
                    ));
                }
            }
        }
    }

    /**
     * Парсит MERGEFIELD-инструкцию: "MERGEFIELD CustomerName \\* MERGEFORMAT"
     * → "CustomerName". Также допускаем `<w:instr>MERGEFIELD CustomerName</w:instr>`.
     */
    private function extractMergefieldName(string $instr): ?string
    {
        if (! preg_match('/^\s*MERGEFIELD\s+("?)([\w\.]+)(\1)/i', $instr, $m)) {
            return null;
        }

        return $this->sanitizeVarName($m[2]);
    }

    private function sanitizeVarName(string $raw): string
    {
        $clean = trim($raw);
        // Допускаем буквы/цифры/_./- . Остальное оставляем — caller-side
        // могут sanitiz'нуть отдельно.
        return $clean;
    }

    private function extractInnerText(\DOMElement $el): string
    {
        $text = '';
        foreach ($el->getElementsByTagNameNS(OoxmlNs::W, 't') as $t) {
            if ($t instanceof \DOMElement) {
                $text .= $t->textContent;
            }
        }

        return trim($text);
    }
}
