<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Reader;

/**
 * The footnote texts from `word/footnotes.xml`, by their identifiers.
 *
 * The formatting of a footnote is deliberately not carried over: at the foot of
 * the page it is set in its own size, and the markup of the source paragraph
 * decides nothing there. What is needed is the text.
 */
final class FootnoteReader
{
    /** @var array<int, string> */
    private array $byId = [];

    public function __construct(?\DOMDocument $footnotesXml)
    {
        if ($footnotesXml === null) {
            return;
        }

        foreach ($footnotesXml->getElementsByTagNameNS(OoxmlNs::W, 'footnote') as $footnote) {
            // The separators (`separator`, `continuationSeparator`) are internal
            // presentation parts and carry no text.
            $type = $footnote->getAttributeNS(OoxmlNs::W, 'type');
            if ($type !== '') {
                continue;
            }

            $id = $footnote->getAttributeNS(OoxmlNs::W, 'id');
            if ($id === '' || ! ctype_digit(ltrim($id, '-'))) {
                continue;
            }

            $parts = [];
            $first = true;
            foreach ($footnote->getElementsByTagNameNS(OoxmlNs::W, 'r') as $run) {
                // A footnote mark is its number. Word places it either with the
                // internal `w:footnoteRef` or simply as a superscript in the
                // first run. The print engine draws the numbering — otherwise
                // the result reads «1. 1Текст».
                $isMarker = $run->getElementsByTagNameNS(OoxmlNs::W, 'footnoteRef')->length > 0
                    || ($first && $this->isSuperscript($run));
                $first = false;
                if ($isMarker) {
                    continue;
                }
                foreach ($run->getElementsByTagNameNS(OoxmlNs::W, 't') as $t) {
                    $parts[] = $t->textContent;
                }
            }
            $text = trim(preg_replace('/\s+/u', ' ', implode('', $parts)) ?? '');
            if ($text !== '') {
                $this->byId[(int) $id] = $text;
            }
        }
    }

    private function isSuperscript(\DOMElement $run): bool
    {
        foreach ($run->getElementsByTagNameNS(OoxmlNs::W, 'vertAlign') as $vertAlign) {
            if ($vertAlign->getAttributeNS(OoxmlNs::W, 'val') === 'superscript') {
                return true;
            }
        }

        return false;
    }

    public function text(int $id): ?string
    {
        return $this->byId[$id] ?? null;
    }
}
