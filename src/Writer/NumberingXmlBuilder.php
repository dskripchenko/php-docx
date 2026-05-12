<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Writer;

/**
 * Генерирует `word/numbering.xml` со стандартными abstract и concrete
 * numbering definitions для bullet и ordered списков (3 уровня).
 *
 * Abstract IDs:
 *  - 0 — bullet (●/○/■ для уровней 0/1/2)
 *  - 1 — ordered (decimal/lower-alpha/lower-roman)
 *
 * Concrete numId:
 *  - 1 — bullet list (inst of abstract 0)
 *  - 2 — ordered list (inst of abstract 1)
 *
 * Эти IDs стабильны — BodyXmlBuilder использует их при эмиссии
 * `<w:numId w:val="1|2"/>` в параграфах списка.
 */
final class NumberingXmlBuilder
{
    public const int BULLET_NUM_ID = 1;
    public const int ORDERED_NUM_ID = 2;
    public const int MAX_LEVELS = 3;

    public function render(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:numbering xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .$this->renderBulletAbstractNum(0)
            .$this->renderOrderedAbstractNum(1)
            .'<w:num w:numId="'.self::BULLET_NUM_ID.'"><w:abstractNumId w:val="0"/></w:num>'
            .'<w:num w:numId="'.self::ORDERED_NUM_ID.'"><w:abstractNumId w:val="1"/></w:num>'
            .'</w:numbering>';
    }

    private function renderBulletAbstractNum(int $id): string
    {
        $bullets = ['●', '○', '■'];
        $xml = '<w:abstractNum w:abstractNumId="'.$id.'">';
        for ($lvl = 0; $lvl < self::MAX_LEVELS; $lvl++) {
            $bullet = $bullets[$lvl] ?? '●';
            $indent = 720 + ($lvl * 360); // 0.5" + 0.25" per level
            $hanging = 360;
            $xml .= '<w:lvl w:ilvl="'.$lvl.'">'
                .'<w:start w:val="1"/>'
                .'<w:numFmt w:val="bullet"/>'
                .'<w:lvlText w:val="'.XmlEscape::attr($bullet).'"/>'
                .'<w:lvlJc w:val="left"/>'
                .'<w:pPr><w:ind w:left="'.$indent.'" w:hanging="'.$hanging.'"/></w:pPr>'
                .'<w:rPr><w:rFonts w:ascii="Symbol" w:hAnsi="Symbol" w:hint="default"/></w:rPr>'
                .'</w:lvl>';
        }
        $xml .= '</w:abstractNum>';

        return $xml;
    }

    private function renderOrderedAbstractNum(int $id): string
    {
        $formats = ['decimal', 'lowerLetter', 'lowerRoman'];
        $separators = ['%1.', '%2.', '%3.'];
        $xml = '<w:abstractNum w:abstractNumId="'.$id.'">';
        for ($lvl = 0; $lvl < self::MAX_LEVELS; $lvl++) {
            $fmt = $formats[$lvl] ?? 'decimal';
            $sep = $separators[$lvl] ?? '%1.';
            $indent = 720 + ($lvl * 360);
            $hanging = 360;
            $xml .= '<w:lvl w:ilvl="'.$lvl.'">'
                .'<w:start w:val="1"/>'
                .'<w:numFmt w:val="'.$fmt.'"/>'
                .'<w:lvlText w:val="'.$sep.'"/>'
                .'<w:lvlJc w:val="left"/>'
                .'<w:pPr><w:ind w:left="'.$indent.'" w:hanging="'.$hanging.'"/></w:pPr>'
                .'</w:lvl>';
        }
        $xml .= '</w:abstractNum>';

        return $xml;
    }
}
