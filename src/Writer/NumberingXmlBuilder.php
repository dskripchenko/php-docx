<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Writer;

use Dskripchenko\PhpDocx\Element\ListFormat;

/**
 * Generates `word/numbering.xml` with the abstract and concrete numbering
 * definitions for bullet and ordered lists (3 levels).
 *
 * Standard fixed IDs:
 *  - BULLET_NUM_ID=1   — bullet list (●/○/■)
 *  - ORDERED_NUM_ID=2  — decimal-decimal-decimal with startAt=1
 *
 * Custom instances (via instanceFor(format, startAt)):
 *  Every unique (format, startAt) pair gets its own numId/abstractNumId. This
 *  is what `<ol type="A" start="3">` and similar flexible lists need.
 */
final class NumberingXmlBuilder
{
    public const BULLET_NUM_ID = 1;

    public const ORDERED_NUM_ID = 2;

    public const MAX_LEVELS = 3;

    /** @var array<string, int> Map "format._startAt" → numId */
    private array $instanceMap = [];

    /** @var list<array{format: ListFormat, startAt: int}> The registered custom instances */
    private array $customInstances = [];

    private int $nextNumId = 3;

    private bool $used = false;

    public function isUsed(): bool
    {
        return $this->used;
    }

    /**
     * Returns (and registers) the numId for the given (format, startAt) pair.
     * The standard pairs (bullet, decimal+1) return the fixed IDs so that
     * numbering.xml does not accumulate duplicates.
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

        // A custom abstract+concrete pair for every non-standard instance.
        $abstractIdCursor = 2;
        foreach ($this->customInstances as $inst) {
            $key = $inst['format']->value.'_'.$inst['startAt'];
            $numId = $this->instanceMap[$key];
            $xml .= $this->renderOrderedAbstractNum($abstractIdCursor, $inst['format'], $inst['startAt']);
            $xml .= '<w:num w:numId="'.$numId.'"><w:abstractNumId w:val="'.$abstractIdCursor.'"/></w:num>';
            $abstractIdCursor++;
        }

        // The standard concrete instances come after the custom ones — the
        // order does not matter to Word or Pages.
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
     * The ordered abstract: level 0 uses the given $format/$startAt, levels 1
     * and 2 use lowerLetter/lowerRoman (Word's standard nested numbering).
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
