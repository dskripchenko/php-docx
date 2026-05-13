<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Reader;

/**
 * Phase 8 — extract watermark text из header XML.
 *
 * Поддерживаем два формата:
 *
 * 1. **VML** (legacy, наш writer и старый Word):
 *    `<v:shape type="#_x0000_t136"><v:textpath string="…"/></v:shape>`
 *    В DOCX-файле обычно завёрнуто в `<w:pict>` внутри `<w:r>`.
 *
 * 2. **DrawingML SDT** (Word 2013+):
 *    `<w:sdt>` с `<w:sdtPr><w:tag w:val="..."/></w:sdtPr>` + `wp14:Watermark`
 *    children. Текст watermark'а сидит в любом `<w:t>` внутри SDT.
 *
 * Mutates `$headerDoc` — удаляет watermark shape/SDT из DOM чтобы
 * BodyReader не вытащил его как обычный run.
 */
final class WatermarkExtractor
{
    /**
     * Извлекает текст watermark'а из header DOMDocument. Возвращает null если
     * не найден.
     */
    public function extract(\DOMDocument $headerDoc): ?string
    {
        $text = $this->extractVml($headerDoc);
        if ($text !== null) {
            return $text;
        }

        return $this->extractSdt($headerDoc);
    }

    private function extractVml(\DOMDocument $doc): ?string
    {
        $shapes = $doc->getElementsByTagNameNS(OoxmlNs::V, 'shape');
        $found = null;
        // Сначала найдём; потом отдельно удалим (изменять collection during
        // iteration не безопасно).
        $shapesToRemove = [];
        for ($i = 0; $i < $shapes->length; $i++) {
            $shape = $shapes->item($i);
            if (! $shape instanceof \DOMElement) {
                continue;
            }
            $type = $shape->getAttribute('type');
            if (! str_contains($type, '_t136')) {
                continue;
            }
            $textpath = OoxmlNs::firstChild($shape, OoxmlNs::V, 'textpath');
            if ($textpath === null) {
                continue;
            }
            $str = $textpath->getAttribute('string');
            if ($str === '') {
                continue;
            }
            if ($found === null) {
                $found = $str;
            }
            $shapesToRemove[] = $shape;
        }

        foreach ($shapesToRemove as $shape) {
            $this->removeWithEmptyParents($shape);
        }

        return $found;
    }

    private function extractSdt(\DOMDocument $doc): ?string
    {
        // SDT'ы могут быть в любом контексте; берём только watermark'ы.
        $sdts = $doc->getElementsByTagNameNS(OoxmlNs::W, 'sdt');
        $sdtsToRemove = [];
        $found = null;
        for ($i = 0; $i < $sdts->length; $i++) {
            $sdt = $sdts->item($i);
            if (! $sdt instanceof \DOMElement) {
                continue;
            }
            if (! $this->isWatermarkSdt($sdt)) {
                continue;
            }
            $text = $this->collectInnerText($sdt);
            if ($text !== '' && $found === null) {
                $found = $text;
            }
            $sdtsToRemove[] = $sdt;
        }
        foreach ($sdtsToRemove as $sdt) {
            $this->removeWithEmptyParents($sdt);
        }

        return $found;
    }

    private function isWatermarkSdt(\DOMElement $sdt): bool
    {
        $sdtPr = OoxmlNs::firstChild($sdt, OoxmlNs::W, 'sdtPr');
        if ($sdtPr === null) {
            return false;
        }
        // Tag/alias contains "watermark" (case-insensitive)
        foreach (['tag', 'alias'] as $attrTag) {
            $el = OoxmlNs::firstChild($sdtPr, OoxmlNs::W, $attrTag);
            if ($el === null) {
                continue;
            }
            $val = OoxmlNs::wVal($el) ?? '';
            if (str_contains(strtolower($val), 'watermark')) {
                return true;
            }
        }

        return false;
    }

    private function collectInnerText(\DOMElement $sdt): string
    {
        $text = '';
        foreach ($sdt->getElementsByTagNameNS(OoxmlNs::W, 't') as $t) {
            if ($t instanceof \DOMElement) {
                $text .= $t->textContent;
            }
        }

        return trim($text);
    }

    private function removeWithEmptyParents(\DOMElement $node): void
    {
        $parent = $node->parentNode;
        $node->parentNode?->removeChild($node);
        // Подчищаем пустые wrapper'ы (w:pict, w:r, w:p) которые
        // остались бы пустыми после удаления watermark'а.
        while ($parent instanceof \DOMElement) {
            if ($parent->hasChildNodes()) {
                // Проверяем — только нетекстовые элементы или непустой текст.
                $allEmpty = true;
                foreach ($parent->childNodes as $c) {
                    if ($c instanceof \DOMElement) {
                        $allEmpty = false;
                        break;
                    }
                    if ($c instanceof \DOMText && trim($c->textContent) !== '') {
                        $allEmpty = false;
                        break;
                    }
                }
                if (! $allEmpty) {
                    return;
                }
            }
            $next = $parent->parentNode;
            // Не сносим root <w:hdr>/<w:ftr>.
            if (! $next instanceof \DOMElement) {
                return;
            }
            $next->removeChild($parent);
            $parent = $next;
        }
    }
}
