<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Writer;

use Dskripchenko\PhpDocx\Style\Alignment;
use Dskripchenko\PhpDocx\Style\ParagraphStyle;
use Dskripchenko\PhpDocx\Style\RunStyle;
use Dskripchenko\PhpDocx\Style\StyleRegistry;

/**
 * Generates `word/styles.xml` out of a StyleRegistry. Every styleId becomes a
 * <w:style> with w:type="paragraph" + w:styleId + nested <w:rPr>/<w:pPr>.
 *
 * It also emits the mandatory docDefaults, so that default text has sensible
 * values in Word.
 */
final class StylesXmlBuilder
{
    public function __construct(
        private readonly StyleRegistry $registry,
    ) {}

    public function render(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .$this->renderDocDefaults()
            .$this->renderStyles()
            .'</w:styles>';

        return $xml;
    }

    private function renderDocDefaults(): string
    {
        return '<w:docDefaults>'
            .'<w:rPrDefault>'
            .'<w:rPr>'
            .'<w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/>'
            .'<w:sz w:val="22"/><w:szCs w:val="22"/>'
            .'</w:rPr>'
            .'</w:rPrDefault>'
            .'<w:pPrDefault>'
            .'<w:pPr><w:spacing w:after="0" w:line="276" w:lineRule="auto"/></w:pPr>'
            .'</w:pPrDefault>'
            .'</w:docDefaults>';
    }

    private function renderStyles(): string
    {
        $xml = '';
        foreach ($this->registry->all() as $styleId => $def) {
            $xml .= $this->renderStyle($styleId, $def);
        }

        return $xml;
    }

    /**
     * @param  array{run: RunStyle, paragraph: ParagraphStyle, type: 'paragraph'|'character'}  $def
     */
    private function renderStyle(string $styleId, array $def): string
    {
        $type = $def['type'];
        $name = self::humanize($styleId);

        $rPr = $this->renderRunProperties($def['run']);
        $pPr = $type === 'paragraph' ? $this->renderParagraphProperties($def['paragraph']) : '';

        // The heading styles are based on Normal with next=Normal.
        $baseAndNext = str_starts_with($styleId, 'Heading') || $styleId === 'ListParagraph'
            ? '<w:basedOn w:val="Normal"/><w:next w:val="Normal"/>'
            : '';

        // qFormat marks the style as primary for the Word UI (it shows in the gallery).
        $qFormat = str_starts_with($styleId, 'Heading') ? '<w:qFormat/>' : '';

        return '<w:style w:type="'.$type.'" w:styleId="'.$styleId.'">'
            .'<w:name w:val="'.XmlEscape::attr($name).'"/>'
            .$baseAndNext
            .$qFormat
            .$pPr
            .$rPr
            .'</w:style>';
    }

    private function renderRunProperties(RunStyle $s): string
    {
        if ($s->isEmpty()) {
            return '';
        }
        $rPr = '';
        if ($s->fontFamily !== null) {
            $f = XmlEscape::attr($s->fontFamily);
            $rPr .= '<w:rFonts w:ascii="'.$f.'" w:hAnsi="'.$f.'" w:cs="'.$f.'"/>';
        }
        if ($s->bold) {
            $rPr .= '<w:b/><w:bCs/>';
        }
        if ($s->italic) {
            $rPr .= '<w:i/><w:iCs/>';
        }
        if ($s->underline) {
            $rPr .= '<w:u w:val="single"/>';
        }
        if ($s->strikethrough) {
            $rPr .= '<w:strike/>';
        }
        if ($s->color !== null) {
            $rPr .= '<w:color w:val="'.$s->color.'"/>';
        }
        if ($s->sizeHalfPoints !== null) {
            $rPr .= '<w:sz w:val="'.$s->sizeHalfPoints.'"/><w:szCs w:val="'.$s->sizeHalfPoints.'"/>';
        }

        return '<w:rPr>'.$rPr.'</w:rPr>';
    }

    private function renderParagraphProperties(ParagraphStyle $s): string
    {
        $pPr = '';
        if ($s->spaceBeforeTwips !== 0 || $s->spaceAfterTwips !== 0) {
            $attrs = [];
            if ($s->spaceBeforeTwips !== 0) {
                $attrs[] = 'w:before="'.$s->spaceBeforeTwips.'"';
            }
            if ($s->spaceAfterTwips !== 0) {
                $attrs[] = 'w:after="'.$s->spaceAfterTwips.'"';
            }
            $pPr .= '<w:spacing '.implode(' ', $attrs).'/>';
        }
        if ($s->indentLeftTwips !== 0 || $s->indentRightTwips !== 0) {
            $attrs = [];
            if ($s->indentLeftTwips !== 0) {
                $attrs[] = 'w:left="'.$s->indentLeftTwips.'"';
            }
            if ($s->indentRightTwips !== 0) {
                $attrs[] = 'w:right="'.$s->indentRightTwips.'"';
            }
            $pPr .= '<w:ind '.implode(' ', $attrs).'/>';
        }
        if ($s->alignment !== Alignment::Start) {
            $pPr .= '<w:jc w:val="'.$s->alignment->value.'"/>';
        }

        if ($pPr === '') {
            return '';
        }

        return '<w:pPr>'.$pPr.'</w:pPr>';
    }

    /** `Heading1` → `Heading 1`, `ListParagraph` → `List Paragraph`. */
    private static function humanize(string $id): string
    {
        $out = (string) preg_replace('/(?<!^)([A-Z])/', ' $1', $id);

        return (string) preg_replace('/([a-zA-Z])(\d)/', '$1 $2', $out);
    }
}
