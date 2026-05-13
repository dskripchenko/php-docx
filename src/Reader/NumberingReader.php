<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Reader;

use Dskripchenko\PhpDocx\Element\ListFormat;

/**
 * Phase 5 — NumberingReader.
 *
 * Парсит `word/numbering.xml`:
 *  - `<w:abstractNum w:abstractNumId="N">` с детьми `<w:lvl w:ilvl="0..">`
 *    содержащими `<w:numFmt w:val="decimal|bullet|...">` и
 *    `<w:start w:val="1">`.
 *  - `<w:num w:numId="M"><w:abstractNumId w:val="N"/></w:num>` — concrete
 *    instance с возможными overrides `<w:lvlOverride>/<w:startOverride>`.
 *
 * Resolved-state в NumberingDefinitions: numId → level → {format, startAt}.
 */
final class NumberingReader
{
    public function read(?\DOMDocument $numberingXml): NumberingDefinitions
    {
        if ($numberingXml === null) {
            return new NumberingDefinitions;
        }
        $root = $numberingXml->documentElement;
        if ($root === null) {
            return new NumberingDefinitions;
        }

        // Abstract definitions: abstractNumId → level → {format, startAt}.
        $abstracts = [];
        foreach (OoxmlNs::children($root, OoxmlNs::W, 'abstractNum') as $abstract) {
            $id = $abstract->getAttributeNS(OoxmlNs::W, 'abstractNumId');
            if ($id === '' || ! ctype_digit($id)) {
                continue;
            }
            $abstracts[(int) $id] = $this->readAbstractLevels($abstract);
        }

        // Concrete instances: numId → abstractNumId + overrides.
        $byNumId = [];
        foreach (OoxmlNs::children($root, OoxmlNs::W, 'num') as $num) {
            $numId = $num->getAttributeNS(OoxmlNs::W, 'numId');
            if ($numId === '' || ! ctype_digit($numId)) {
                continue;
            }
            $abstractRef = OoxmlNs::firstChild($num, OoxmlNs::W, 'abstractNumId');
            if ($abstractRef === null) {
                continue;
            }
            $abstractId = OoxmlNs::wVal($abstractRef);
            if ($abstractId === null || ! ctype_digit($abstractId)) {
                continue;
            }
            $absLevels = $abstracts[(int) $abstractId] ?? [];

            // Apply lvlOverride/startOverride.
            foreach (OoxmlNs::children($num, OoxmlNs::W, 'lvlOverride') as $override) {
                $ilvlAttr = $override->getAttributeNS(OoxmlNs::W, 'ilvl');
                if (! ctype_digit($ilvlAttr)) {
                    continue;
                }
                $ilvl = (int) $ilvlAttr;
                $startOverride = OoxmlNs::firstChild($override, OoxmlNs::W, 'startOverride');
                if ($startOverride !== null) {
                    $val = OoxmlNs::wVal($startOverride);
                    if ($val !== null && ctype_digit($val)) {
                        $absLevels[$ilvl]['startAt'] = (int) $val;
                    }
                }
                // <w:lvlOverride><w:lvl>...</w:lvl></w:lvlOverride> — полное переопределение
                $lvlEl = OoxmlNs::firstChild($override, OoxmlNs::W, 'lvl');
                if ($lvlEl !== null) {
                    $absLevels[$ilvl] = $this->readSingleLevel($lvlEl);
                }
            }

            $byNumId[(int) $numId] = $absLevels;
        }

        return new NumberingDefinitions($byNumId);
    }

    /**
     * @return array<int, array{format: ListFormat, startAt: int}>
     */
    private function readAbstractLevels(\DOMElement $abstractNum): array
    {
        $levels = [];
        foreach (OoxmlNs::children($abstractNum, OoxmlNs::W, 'lvl') as $lvl) {
            $ilvlAttr = $lvl->getAttributeNS(OoxmlNs::W, 'ilvl');
            if (! ctype_digit($ilvlAttr)) {
                continue;
            }
            $levels[(int) $ilvlAttr] = $this->readSingleLevel($lvl);
        }

        return $levels;
    }

    /**
     * @return array{format: ListFormat, startAt: int}
     */
    private function readSingleLevel(\DOMElement $lvl): array
    {
        $numFmt = OoxmlNs::firstChild($lvl, OoxmlNs::W, 'numFmt');
        $start = OoxmlNs::firstChild($lvl, OoxmlNs::W, 'start');

        $format = ListFormat::Decimal;
        if ($numFmt !== null) {
            $val = OoxmlNs::wVal($numFmt);
            $format = match ($val) {
                'bullet' => ListFormat::Bullet,
                'lowerLetter' => ListFormat::LowerLetter,
                'upperLetter' => ListFormat::UpperLetter,
                'lowerRoman' => ListFormat::LowerRoman,
                'upperRoman' => ListFormat::UpperRoman,
                default => ListFormat::Decimal,
            };
        }
        $startAt = 1;
        if ($start !== null) {
            $val = OoxmlNs::wVal($start);
            if ($val !== null && ctype_digit($val)) {
                $startAt = max(1, (int) $val);
            }
        }

        return ['format' => $format, 'startAt' => $startAt];
    }
}
