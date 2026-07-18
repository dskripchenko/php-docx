<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Tests\Writer;

use Dskripchenko\PhpDocx\Build\DocumentBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * String-level guards for the two ECMA-376 violations the XSD validation
 * pass surfaced (scripts/conformance/xsd-check.sh runs the real schemas
 * in CI; these keep the fast suite honest without the schema download):
 *
 *  - CT_PPrBase enforces child order: pBdr → spacing → ind → … → jc.
 *    The writer used to emit jc first and pBdr last.
 *  - CT_PageMar requires the w:gutter attribute; it was omitted.
 */
final class OoxmlConformanceTest extends TestCase
{
    private function documentXml(callable $build): string
    {
        $bytes = $build(DocumentBuilder::new())->toBytes();
        $tmp = tempnam(sys_get_temp_dir(), 'docx-conf-');
        file_put_contents($tmp, $bytes);
        $zip = new \ZipArchive;
        $zip->open($tmp);
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        unlink($tmp);

        return $xml;
    }

    #[Test]
    public function ppr_children_follow_schema_order(): void
    {
        $xml = $this->documentXml(fn (DocumentBuilder $b) => $b
            ->paragraph(fn ($p) => $p->text('x')
                ->alignJustify()
                ->spacing(before: 120, after: 120)
                ->indent(left: 360)));

        $pPr = null;
        preg_match('@<w:pPr>(.*?)</w:pPr>@', $xml, $m);
        self::assertNotEmpty($m, 'paragraph must carry a pPr');
        $pPr = $m[1];

        $spacing = strpos($pPr, '<w:spacing');
        $ind = strpos($pPr, '<w:ind');
        $jc = strpos($pPr, '<w:jc');
        self::assertNotFalse($spacing);
        self::assertNotFalse($ind);
        self::assertNotFalse($jc);
        self::assertLessThan($ind, $spacing, 'spacing must precede ind');
        self::assertLessThan($jc, $ind, 'ind must precede jc');
    }

    #[Test]
    public function page_margins_carry_the_required_gutter(): void
    {
        $xml = $this->documentXml(fn (DocumentBuilder $b) => $b->paragraph('x'));

        self::assertMatchesRegularExpression(
            '@<w:pgMar [^>]*w:gutter="\d+"@',
            $xml,
            'CT_PageMar requires w:gutter',
        );
    }
}
