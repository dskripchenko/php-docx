<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Tests\Writer;

use Dskripchenko\PhpDocx\Html\Converter;
use Dskripchenko\PhpDocx\Writer\Word2007Writer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests: HTML → Converter → Document → Word2007Writer → DOCX bytes.
 * They check that the whole pipeline holds together end to end.
 */
final class IntegrationTest extends TestCase
{
    #[Test]
    public function it_renders_simple_html_end_to_end(): void
    {
        $html = '<h1>Invoice</h1><p>Total: <strong>500 USD</strong></p>';
        $doc = (new Converter)->fromHtml($html);
        $bytes = (new Word2007Writer)->write($doc);

        self::assertSame('PK', substr($bytes, 0, 2));

        $documentXml = self::extractXml($bytes, 'word/document.xml');
        self::assertStringContainsString('Invoice', $documentXml);
        self::assertStringContainsString('Total: ', $documentXml);
        self::assertStringContainsString('500 USD', $documentXml);
        self::assertStringContainsString('<w:pStyle w:val="Heading1"/>', $documentXml);
        self::assertStringContainsString('<w:b/>', $documentXml);
    }

    #[Test]
    public function it_renders_table_with_styled_cells(): void
    {
        $html = '<table>'
            .'<tr><td style="width: 36%; background: #f0f0f0">label</td><td style="width: 64%">value</td></tr>'
            .'<tr><td>A</td><td>B</td></tr>'
            .'</table>';
        $doc = (new Converter)->fromHtml($html);
        $bytes = (new Word2007Writer)->write($doc);

        $xml = self::extractXml($bytes, 'word/document.xml');
        self::assertStringContainsString('<w:tbl>', $xml);
        self::assertStringContainsString('<w:tcW w:w="1800" w:type="pct"/>', $xml);
        self::assertStringContainsString('<w:tcW w:w="3200" w:type="pct"/>', $xml);
        self::assertStringContainsString('label', $xml);
        self::assertStringContainsString('value', $xml);
        self::assertStringContainsString('f0f0f0', $xml);
    }

    #[Test]
    public function it_embeds_image_with_relationship_and_media_file(): void
    {
        // 1x1 PNG (transparent)
        $pngBin = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
        $b64 = base64_encode($pngBin);
        $html = '<p><img src="data:image/png;base64,'.$b64.'" width="100" height="50"></p>';

        $doc = (new Converter)->fromHtml($html);
        $bytes = (new Word2007Writer)->write($doc);

        // The media file is there
        $tmp = tempnam(sys_get_temp_dir(), 'docx-int-');
        file_put_contents($tmp, $bytes);
        try {
            $zip = new \ZipArchive;
            $zip->open($tmp);
            $hasMedia = false;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                if (str_starts_with($zip->getNameIndex($i), 'word/media/')) {
                    $hasMedia = true;
                    break;
                }
            }
            $contentTypes = (string) $zip->getFromName('[Content_Types].xml');
            $documentRels = (string) $zip->getFromName('word/_rels/document.xml.rels');
            $documentXml = (string) $zip->getFromName('word/document.xml');
            $zip->close();
        } finally {
            @unlink($tmp);
        }

        self::assertTrue($hasMedia, 'word/media/* должен присутствовать');
        self::assertStringContainsString('Extension="png"', $contentTypes);
        self::assertStringContainsString('image/png', $contentTypes);
        self::assertStringContainsString('Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image"', $documentRels);
        self::assertStringContainsString('<w:drawing>', $documentXml);
        self::assertStringContainsString('<a:blip r:embed="', $documentXml);
    }

    #[Test]
    public function it_renders_polis_like_template_without_errors(): void
    {
        $html = <<<'HTML'
        <h1>Страховой полис</h1>
        <p style="text-align: center">№ ВЗР-2026-000123</p>
        <h2>1. Страхователь</h2>
        <table>
            <tr><td style="width: 36%">ФИО</td><td><strong>Иванов Иван Петрович</strong></td></tr>
            <tr><td style="width: 36%">Дата рождения</td><td>12.04.1980</td></tr>
            <tr><td style="width: 36%">Адрес</td><td>г. Москва, ул. Лесная, 8</td></tr>
        </table>
        <h2>2. Семья</h2>
        <table>
            <thead><tr><th>№</th><th>ФИО</th><th>Дата рождения</th></tr></thead>
            <tbody>
                <tr><td>1</td><td>Иванов Иван</td><td>12.04.1980</td></tr>
                <tr><td>2</td><td>Иванова Мария</td><td>23.07.1982</td></tr>
            </tbody>
        </table>
        <hr>
        <p style="text-align: center">Печать оплачена</p>
        HTML;

        $doc = (new Converter)->fromHtml($html);
        $bytes = (new Word2007Writer)->write($doc);

        self::assertSame('PK', substr($bytes, 0, 2));

        $xml = self::extractXml($bytes, 'word/document.xml');
        self::assertStringContainsString('Страховой полис', $xml);
        self::assertStringContainsString('Иванов Иван Петрович', $xml);
        self::assertStringContainsString('<w:tblHeader/>', $xml);
        self::assertStringContainsString('<w:pBdr>', $xml);  // hr
    }

    private static function extractXml(string $bytes, string $internalPath): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'docx-int-');
        file_put_contents($tmp, $bytes);
        try {
            $zip = new \ZipArchive;
            $zip->open($tmp);
            $contents = (string) $zip->getFromName($internalPath);
            $zip->close();
        } finally {
            @unlink($tmp);
        }

        return $contents;
    }
}
