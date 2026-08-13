<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Tests\Reader;

use Dskripchenko\PhpDocx\Reader\StylesResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The formatting of the paragraph mark does not leak onto the text.
 *
 * `w:pPr/w:rPr` describes the ¶ character — what anything typed at the end of
 * the paragraph will look like. Word does not touch the existing runs. These
 * properties used to be mixed into the base run style, and any one of them
 * spread across the whole paragraph: in the reference insurance policy the
 * paragraph mark was flagged bold, and the entire document printed bold — the
 * lines came out 16% wider than the original.
 */
final class ParagraphMarkFormattingTest extends TestCase
{
    #[Test]
    public function bold_paragraph_mark_does_not_make_the_text_bold(): void
    {
        $xml = new \DOMDocument;
        $xml->loadXML(
            '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.
            '<w:p><w:pPr><w:rPr><w:b/></w:rPr></w:pPr>'.
            '<w:r><w:rPr><w:sz w:val="16"/></w:rPr><w:t>текст</w:t></w:r>'.
            '</w:p></w:body></w:document>'
        );

        $paragraph = $xml->getElementsByTagNameNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'p')->item(0);
        self::assertInstanceOf(\DOMElement::class, $paragraph);

        $resolver = new StylesResolver;
        [, $runBase] = $resolver->effectiveStylesForParagraph($paragraph);

        self::assertFalse($runBase->bold);
    }
}
