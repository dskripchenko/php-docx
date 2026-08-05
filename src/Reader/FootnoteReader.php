<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Reader;

/**
 * Тексты сносок из `word/footnotes.xml` по их идентификаторам.
 *
 * Форматирование сноски не переносится намеренно: внизу полосы она набирается
 * своим кеглем, и разметка исходного абзаца там ничего не решает. Нужен текст.
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
            // Разделители (`separator`, `continuationSeparator`) — служебные
            // части оформления, текста в них нет.
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
                // Знак сноски — её номер. Word ставит его либо служебным
                // `w:footnoteRef`, либо просто верхним индексом первым руном.
                // Нумерацию рисует движок печати, иначе выйдет «1. 1Текст».
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
