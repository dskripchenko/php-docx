<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Tests\Reader;

use Dskripchenko\PhpDocx\Reader\OoxmlNs;
use Dskripchenko\PhpDocx\Reader\StylesResolver;
use Dskripchenko\PhpDocx\Reader\ThemeResolver;
use Dskripchenko\PhpDocx\Style\Alignment;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StylesResolverTest extends TestCase
{
    #[Test]
    public function direct_run_bold_resolves_to_runstyle_bold(): void
    {
        $xml = $this->wrapDocument(
            '<w:p><w:r><w:rPr><w:b/></w:rPr><w:t>x</w:t></w:r></w:p>'
        );
        $resolver = new StylesResolver;
        $p = $this->firstParagraph($xml);

        [, $runBase] = $resolver->effectiveStylesForParagraph($p);
        $r = $this->firstRun($p);
        $runStyle = $resolver->effectiveStylesForRun($r, $runBase);

        self::assertTrue($runStyle->bold);
    }

    #[Test]
    public function default_style_overrides_doc_defaults(): void
    {
        // The ECMA-376 cascade: docDefaults → the style with w:default="1" →
        // the named style → direct formatting. Documents routinely say one
        // thing in docDefaults and another in the default style, and the
        // second one has to win.
        //
        // Without this layer the reference policy swelled from five pages to
        // seven: docDefaults promised 8pt after every paragraph while the
        // default style reset them to zero — and each of the 246 paragraphs
        // got the extra 8pt.
        $stylesXml = $this->loadStylesXml(
            '<w:docDefaults><w:pPrDefault><w:pPr><w:spacing w:after="160"/></w:pPr></w:pPrDefault></w:docDefaults>'.
            '<w:style w:type="paragraph" w:default="1" w:styleId="a">'.
            '<w:pPr><w:spacing w:after="0"/></w:pPr></w:style>'
        );
        $resolver = new StylesResolver($stylesXml);

        $p = $this->firstParagraph($this->wrapDocument('<w:p><w:r><w:t>x</w:t></w:r></w:p>'));
        [$paragraphStyle] = $resolver->effectiveStylesForParagraph($p);

        self::assertSame(0, $paragraphStyle->spaceAfterTwips);
    }

    #[Test]
    public function w_b_val_0_disables_bold_from_inherited_style(): void
    {
        // docDefaults say bold:true; a direct <w:b w:val="0"/> turns it off.
        $stylesXml = $this->loadStylesXml(
            '<w:docDefaults><w:rPrDefault><w:rPr><w:b/></w:rPr></w:rPrDefault></w:docDefaults>'
        );
        $resolver = new StylesResolver($stylesXml);

        $xml = $this->wrapDocument('<w:p><w:r><w:rPr><w:b w:val="0"/></w:rPr><w:t>x</w:t></w:r></w:p>');
        $p = $this->firstParagraph($xml);
        [, $runBase] = $resolver->effectiveStylesForParagraph($p);
        $r = $this->firstRun($p);
        $runStyle = $resolver->effectiveStylesForRun($r, $runBase);

        self::assertFalse($runStyle->bold);
    }

    #[Test]
    public function named_paragraph_style_resolves_with_basedOn_chain(): void
    {
        // Heading2 basedOn Heading1 basedOn Normal.
        // Normal:    color = 333333
        // Heading1:  bold = true
        // Heading2:  italic = true
        // Final run from <w:p> with pStyle=Heading2: bold+italic+color.
        $stylesXml = $this->loadStylesXml(
            '<w:style w:type="paragraph" w:styleId="Normal">'
            .'<w:rPr><w:color w:val="333333"/></w:rPr>'
            .'</w:style>'
            .'<w:style w:type="paragraph" w:styleId="Heading1">'
            .'<w:basedOn w:val="Normal"/>'
            .'<w:rPr><w:b/></w:rPr>'
            .'</w:style>'
            .'<w:style w:type="paragraph" w:styleId="Heading2">'
            .'<w:basedOn w:val="Heading1"/>'
            .'<w:rPr><w:i/></w:rPr>'
            .'</w:style>'
        );
        $resolver = new StylesResolver($stylesXml);

        $xml = $this->wrapDocument(
            '<w:p><w:pPr><w:pStyle w:val="Heading2"/></w:pPr>'
            .'<w:r><w:t>X</w:t></w:r></w:p>'
        );
        $p = $this->firstParagraph($xml);
        [$pStyle, $runBase, $headingLevel] = $resolver->effectiveStylesForParagraph($p);
        $r = $this->firstRun($p);
        $runStyle = $resolver->effectiveStylesForRun($r, $runBase);

        self::assertTrue($runStyle->bold);
        self::assertTrue($runStyle->italic);
        self::assertSame('333333', $runStyle->color);
        self::assertSame(2, $headingLevel);
    }

    #[Test]
    public function alignment_jc_center_resolves(): void
    {
        $xml = $this->wrapDocument(
            '<w:p><w:pPr><w:jc w:val="center"/></w:pPr><w:r><w:t>x</w:t></w:r></w:p>'
        );
        $resolver = new StylesResolver;
        $p = $this->firstParagraph($xml);
        [$pStyle] = $resolver->effectiveStylesForParagraph($p);

        self::assertSame(Alignment::Center, $pStyle->alignment);
    }

    #[Test]
    public function indent_left_in_twips(): void
    {
        $xml = $this->wrapDocument(
            '<w:p><w:pPr><w:ind w:left="720"/></w:pPr><w:r><w:t>x</w:t></w:r></w:p>'
        );
        $resolver = new StylesResolver;
        $p = $this->firstParagraph($xml);
        [$pStyle] = $resolver->effectiveStylesForParagraph($p);

        self::assertSame(720, $pStyle->indentLeftTwips);
    }

    #[Test]
    public function indent_hanging_becomes_negative_first_line(): void
    {
        $xml = $this->wrapDocument(
            '<w:p><w:pPr><w:ind w:left="720" w:hanging="360"/></w:pPr><w:r><w:t>x</w:t></w:r></w:p>'
        );
        $resolver = new StylesResolver;
        $p = $this->firstParagraph($xml);
        [$pStyle] = $resolver->effectiveStylesForParagraph($p);

        self::assertSame(720, $pStyle->indentLeftTwips);
        self::assertSame(-360, $pStyle->indentFirstLineTwips);
    }

    #[Test]
    public function font_size_half_points_parses(): void
    {
        $xml = $this->wrapDocument(
            '<w:p><w:r><w:rPr><w:sz w:val="32"/></w:rPr><w:t>X</w:t></w:r></w:p>'
        );
        $resolver = new StylesResolver;
        $p = $this->firstParagraph($xml);
        [, $base] = $resolver->effectiveStylesForParagraph($p);
        $r = $this->firstRun($p);
        $runStyle = $resolver->effectiveStylesForRun($r, $base);

        self::assertSame(32, $runStyle->sizeHalfPoints);
    }

    #[Test]
    public function color_auto_is_ignored(): void
    {
        $xml = $this->wrapDocument(
            '<w:p><w:r><w:rPr><w:color w:val="auto"/></w:rPr><w:t>X</w:t></w:r></w:p>'
        );
        $resolver = new StylesResolver;
        $p = $this->firstParagraph($xml);
        [, $base] = $resolver->effectiveStylesForParagraph($p);
        $r = $this->firstRun($p);
        $runStyle = $resolver->effectiveStylesForRun($r, $base);

        self::assertNull($runStyle->color);
    }

    #[Test]
    public function theme_color_resolves_via_theme_xml(): void
    {
        $themeXml = $this->loadXml(
            '<a:theme xmlns:a="'.OoxmlNs::A.'">'
            .'<a:themeElements>'
            .'<a:clrScheme name="X">'
            .'<a:dk1><a:srgbClr val="000000"/></a:dk1>'
            .'<a:accent1><a:srgbClr val="14B8A6"/></a:accent1>'
            .'</a:clrScheme>'
            .'</a:themeElements>'
            .'</a:theme>'
        );
        $theme = new ThemeResolver($themeXml);
        $resolver = new StylesResolver(stylesXml: null, theme: $theme);

        $xml = $this->wrapDocument(
            '<w:p><w:r><w:rPr><w:color w:themeColor="accent1"/></w:rPr><w:t>X</w:t></w:r></w:p>'
        );
        $p = $this->firstParagraph($xml);
        [, $base] = $resolver->effectiveStylesForParagraph($p);
        $r = $this->firstRun($p);
        $runStyle = $resolver->effectiveStylesForRun($r, $base);

        self::assertSame('14b8a6', $runStyle->color);
    }

    #[Test]
    public function character_style_rStyle_applies(): void
    {
        $stylesXml = $this->loadStylesXml(
            '<w:style w:type="character" w:styleId="Emphasis">'
            .'<w:rPr><w:i/><w:color w:val="FF0000"/></w:rPr>'
            .'</w:style>'
        );
        $resolver = new StylesResolver($stylesXml);

        $xml = $this->wrapDocument(
            '<w:p><w:r><w:rPr><w:rStyle w:val="Emphasis"/></w:rPr><w:t>X</w:t></w:r></w:p>'
        );
        $p = $this->firstParagraph($xml);
        [, $base] = $resolver->effectiveStylesForParagraph($p);
        $r = $this->firstRun($p);
        $runStyle = $resolver->effectiveStylesForRun($r, $base);

        self::assertTrue($runStyle->italic);
        self::assertSame('ff0000', $runStyle->color);
    }

    #[Test]
    public function direct_rPr_overrides_named_style_color(): void
    {
        $stylesXml = $this->loadStylesXml(
            '<w:style w:type="character" w:styleId="Em">'
            .'<w:rPr><w:color w:val="FF0000"/></w:rPr>'
            .'</w:style>'
        );
        $resolver = new StylesResolver($stylesXml);

        $xml = $this->wrapDocument(
            '<w:p><w:r><w:rPr>'
            .'<w:rStyle w:val="Em"/>'
            .'<w:color w:val="00FF00"/>'
            .'</w:rPr><w:t>X</w:t></w:r></w:p>'
        );
        $p = $this->firstParagraph($xml);
        [, $base] = $resolver->effectiveStylesForParagraph($p);
        $r = $this->firstRun($p);
        $runStyle = $resolver->effectiveStylesForRun($r, $base);

        self::assertSame('00ff00', $runStyle->color);
    }

    #[Test]
    public function cyclic_basedOn_doesnt_infinite_loop(): void
    {
        $stylesXml = $this->loadStylesXml(
            '<w:style w:type="paragraph" w:styleId="A">'
            .'<w:basedOn w:val="B"/>'
            .'<w:pPr><w:jc w:val="center"/></w:pPr>'
            .'</w:style>'
            .'<w:style w:type="paragraph" w:styleId="B">'
            .'<w:basedOn w:val="A"/>'
            .'</w:style>'
        );
        $resolver = new StylesResolver($stylesXml);

        $xml = $this->wrapDocument(
            '<w:p><w:pPr><w:pStyle w:val="A"/></w:pPr><w:r><w:t>x</w:t></w:r></w:p>'
        );
        $p = $this->firstParagraph($xml);
        [$pStyle] = $resolver->effectiveStylesForParagraph($p);

        // Some result, and no infinite loop
        self::assertSame(Alignment::Center, $pStyle->alignment);
    }

    #[Test]
    public function numPr_extracts_numId_and_ilvl(): void
    {
        $xml = $this->wrapDocument(
            '<w:p><w:pPr><w:numPr>'
            .'<w:ilvl w:val="2"/><w:numId w:val="5"/>'
            .'</w:numPr></w:pPr>'
            .'<w:r><w:t>item</w:t></w:r></w:p>'
        );
        $resolver = new StylesResolver;
        $p = $this->firstParagraph($xml);
        [, , , $numId, $ilvl] = $resolver->effectiveStylesForParagraph($p);

        self::assertSame(5, $numId);
        self::assertSame(2, $ilvl);
    }

    #[Test]
    public function highlight_resolves(): void
    {
        $xml = $this->wrapDocument(
            '<w:p><w:r><w:rPr><w:highlight w:val="yellow"/></w:rPr><w:t>!</w:t></w:r></w:p>'
        );
        $resolver = new StylesResolver;
        $p = $this->firstParagraph($xml);
        [, $base] = $resolver->effectiveStylesForParagraph($p);
        $r = $this->firstRun($p);
        $runStyle = $resolver->effectiveStylesForRun($r, $base);

        self::assertSame('yellow', $runStyle->highlight);
    }

    #[Test]
    public function font_family_priority_ascii_over_hAnsi(): void
    {
        $xml = $this->wrapDocument(
            '<w:p><w:r><w:rPr>'
            .'<w:rFonts w:ascii="Arial" w:hAnsi="Times New Roman"/>'
            .'</w:rPr><w:t>X</w:t></w:r></w:p>'
        );
        $resolver = new StylesResolver;
        $p = $this->firstParagraph($xml);
        [, $base] = $resolver->effectiveStylesForParagraph($p);
        $r = $this->firstRun($p);
        $runStyle = $resolver->effectiveStylesForRun($r, $base);

        self::assertSame('Arial', $runStyle->fontFamily);
    }

    private function wrapDocument(string $bodyXml): \DOMDocument
    {
        return $this->loadXml(
            '<w:document xmlns:w="'.OoxmlNs::W.'">'
            .'<w:body>'.$bodyXml.'</w:body>'
            .'</w:document>'
        );
    }

    private function loadStylesXml(string $stylesInnerXml): \DOMDocument
    {
        return $this->loadXml(
            '<w:styles xmlns:w="'.OoxmlNs::W.'">'.$stylesInnerXml.'</w:styles>'
        );
    }

    private function loadXml(string $xml): \DOMDocument
    {
        $doc = new \DOMDocument;
        $doc->loadXML($xml);

        return $doc;
    }

    private function firstParagraph(\DOMDocument $doc): \DOMElement
    {
        $p = $doc->getElementsByTagNameNS(OoxmlNs::W, 'p')->item(0);
        if (! $p instanceof \DOMElement) {
            throw new \RuntimeException('No <w:p> in fixture');
        }

        return $p;
    }

    private function firstRun(\DOMElement $paragraph): \DOMElement
    {
        $r = $paragraph->getElementsByTagNameNS(OoxmlNs::W, 'r')->item(0);
        if (! $r instanceof \DOMElement) {
            throw new \RuntimeException('No <w:r> in paragraph');
        }

        return $r;
    }
}
