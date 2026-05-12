<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Writer;

use Dskripchenko\PhpDocx\Element\ListFormat;

/**
 * Генерирует `word/numbering.xml` с абстрактными и конкретными
 * numbering definitions для bullet/ordered списков (3 уровня).
 *
 * Standard fixed IDs:
 *  - BULLET_NUM_ID=1   — bullet list (●/○/■)
 *  - ORDERED_NUM_ID=2  — decimal-decimal-decimal с startAt=1
 *
 * Custom instances (через instanceFor(format, startAt)):
 *  Каждый уникальный (format, startAt) получает свой numId/abstractNumId.
 *  Нужно для `<ol type="A" start="3">` и подобных гибких списков.
 */
final class NumberingXmlBuilder
{
    public const int BULLET_NUM_ID = 1;

    public const int ORDERED_NUM_ID = 2;

    public const int MAX_LEVELS = 3;

    /** @var array<string, int> Map "format._startAt" → numId */
    private array $instanceMap = [];

    /** @var list<array{format: ListFormat, startAt: int}> Зарегистрированные кастомные инстансы */
    private array $customInstances = [];

    private int $nextNumId = 3;

    private bool $used = false;

    public function isUsed(): bool
    {
        return $this->used;
    }

    /**
     * Возвращает (и регистрирует) numId для заданной комбинации (format, startAt).
     * Стандартные комбинации (bullet, decimal+1) возвращают фиксированные ID
     * — не плодим дубликаты в numbering.xml.
     */
    public function instanceFor(ListFormat $format, int $startAt = 1): int
    {
        $this->used = true;
        if ($format === ListFormat::Bullet) {
            return self::BULLET_NUM_ID;
        }
        if ($format === ListFormat::Decimal && $startAt === 1) {
            return self::ORDERED_NUM_ID;
        }
        $key = $format->value.'_'.$startAt;
        if (! isset($this->instanceMap[$key])) {
            $numId = $this->nextNumId++;
            $this->instanceMap[$key] = $numId;
            $this->customInstances[] = ['format' => $format, 'startAt' => $startAt];
        }

        return $this->instanceMap[$key];
    }

    public function render(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:numbering xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">';

        $xml .= $this->renderBulletAbstractNum(0);
        $xml .= $this->renderOrderedAbstractNum(1, ListFormat::Decimal, 1);

        // Custom abstract+concrete для каждого нестандартного инстанса.
        $abstractIdCursor = 2;
        foreach ($this->customInstances as $inst) {
            $key = $inst['format']->value.'_'.$inst['startAt'];
            $numId = $this->instanceMap[$key];
            $xml .= $this->renderOrderedAbstractNum($abstractIdCursor, $inst['format'], $inst['startAt']);
            $xml .= '<w:num w:numId="'.$numId.'"><w:abstractNumId w:val="'.$abstractIdCursor.'"/></w:num>';
            $abstractIdCursor++;
        }

        // Стандартные concrete instances идут после кастомных — порядок
        // не важен для Word/Pages.
        $xml .= '<w:num w:numId="'.self::BULLET_NUM_ID.'"><w:abstractNumId w:val="0"/></w:num>';
        $xml .= '<w:num w:numId="'.self::ORDERED_NUM_ID.'"><w:abstractNumId w:val="1"/></w:num>';

        $xml .= '</w:numbering>';

        return $xml;
    }

    private function renderBulletAbstractNum(int $id): string
    {
        $bullets = ['●', '○', '■'];
        $xml = '<w:abstractNum w:abstractNumId="'.$id.'">';
        for ($lvl = 0; $lvl < self::MAX_LEVELS; $lvl++) {
            $bullet = $bullets[$lvl] ?? '●';
            $indent = 720 + ($lvl * 360);
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

    /**
     * Ordered abstract: level 0 — указанный $format/$startAt, level 1/2 —
     * lowerLetter/lowerRoman (стандартная вложенная нумерация Word).
     */
    private function renderOrderedAbstractNum(int $id, ListFormat $format, int $startAt): string
    {
        $formats = [$format->value, 'lowerLetter', 'lowerRoman'];
        $separators = ['%1.', '%2.', '%3.'];
        $xml = '<w:abstractNum w:abstractNumId="'.$id.'">';
        for ($lvl = 0; $lvl < self::MAX_LEVELS; $lvl++) {
            $fmt = $formats[$lvl] ?? 'decimal';
            $sep = $separators[$lvl] ?? '%1.';
            $indent = 720 + ($lvl * 360);
            $hanging = 360;
            $startVal = $lvl === 0 ? $startAt : 1;
            $xml .= '<w:lvl w:ilvl="'.$lvl.'">'
                .'<w:start w:val="'.$startVal.'"/>'
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
