<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Writer;

use Dskripchenko\PhpDocx\Style\RunStyle;

/**
 * `<w:rFonts>` of a run style: `fontFamily` for the ascii, hAnsi and cs
 * slots, `fontFamilyEastAsia` for the eastAsia slot. Empty when neither is
 * set.
 */
final class RunFonts
{
    public static function render(RunStyle $s): string
    {
        $attrs = '';
        if ($s->fontFamily !== null) {
            $f = XmlEscape::attr($s->fontFamily);
            $attrs .= ' w:ascii="'.$f.'" w:hAnsi="'.$f.'"';
        }
        if ($s->fontFamilyEastAsia !== null) {
            $attrs .= ' w:eastAsia="'.XmlEscape::attr($s->fontFamilyEastAsia).'"';
        }
        if ($s->fontFamily !== null) {
            $attrs .= ' w:cs="'.XmlEscape::attr($s->fontFamily).'"';
        }

        return $attrs === '' ? '' : '<w:rFonts'.$attrs.'/>';
    }
}
