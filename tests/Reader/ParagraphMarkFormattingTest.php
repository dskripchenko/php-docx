<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Tests\Reader;

use Dskripchenko\PhpDocx\Reader\StylesResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Формат знака абзаца не протекает на текст.
 *
 * `w:pPr/w:rPr` описывает символ ¶ — то, каким будет набранное в конце
 * абзаца. Существующие руны Word не трогает. Раньше эти свойства
 * подмешивались в базовый стиль рунов, и любое из них расходилось на весь
 * абзац: в эталонном страховом полисе знак абзаца был помечен жирным, и
 * жирным печатался весь документ — строки выходили на 16% шире оригинала.
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
