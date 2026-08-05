<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Tests\Writer;

use Dskripchenko\PhpDocx\Document;
use Dskripchenko\PhpDocx\Element\Image;
use Dskripchenko\PhpDocx\Element\ImageFormat;
use Dskripchenko\PhpDocx\Element\Paragraph;
use Dskripchenko\PhpDocx\Element\Run;
use Dskripchenko\PhpDocx\Section;
use Dskripchenko\PhpDocx\Writer\Word2007Writer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * Ссылка внутри колонтитула разрешается относительно rels ЕГО части.
 *
 * `r:embed="rIdN"` в `header1.xml` Word ищет в `word/_rels/header1.xml.rels`.
 * Пока картинки колонтитула регистрировались в rels документа, Word их не
 * находил: знак в шапке печатался пустым квадратом с крестом, а сам файл
 * объявлялся повреждённым — «обнаружено содержимое, которое не удалось
 * прочитать».
 */
final class HeaderRelationshipsTest extends TestCase
{
    private function pngImage(): Image
    {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        );

        return new Image((string) $png, ImageFormat::Png, widthEmu: 190500, heightEmu: 190500);
    }

    /** @return array{parts: array<string, string>, rels: array<string, string>} */
    private function unpack(string $bytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'docx-').'.docx';
        file_put_contents($path, $bytes);

        $zip = new ZipArchive;
        $zip->open($path);
        $parts = [];
        $rels = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->statIndex($i)['name'];
            if (str_contains($name, '/_rels/')) {
                $rels[$name] = (string) $zip->getFromIndex($i);
            } else {
                $parts[$name] = (string) $zip->getFromIndex($i);
            }
        }
        $zip->close();
        @unlink($path);

        return ['parts' => $parts, 'rels' => $rels];
    }

    #[Test]
    public function an_image_in_the_header_is_declared_in_the_header_rels(): void
    {
        $doc = new Document(new Section(
            body: [new Paragraph([new Run('тело')])],
            header: [new Paragraph([$this->pngImage()])],
        ));

        ['parts' => $parts, 'rels' => $rels] = $this->unpack((new Word2007Writer)->write($doc));

        self::assertArrayHasKey('word/header1.xml', $parts);
        self::assertArrayHasKey('word/_rels/header1.xml.rels', $rels, 'у колонтитула нет своего rels-файла');

        preg_match('/r:embed="([^"]+)"/', $parts['word/header1.xml'], $m);
        self::assertNotEmpty($m, 'картинка не попала в колонтитул');

        self::assertStringContainsString(
            'Id="'.$m[1].'"',
            $rels['word/_rels/header1.xml.rels'],
            'ссылка колонтитула не объявлена в его собственном rels',
        );
    }

    #[Test]
    public function every_reference_resolves_within_its_own_part(): void
    {
        // Целостность пакета целиком: Word проверяет именно это.
        $doc = new Document(new Section(
            body: [new Paragraph([$this->pngImage()]), new Paragraph([new Run('тело')])],
            header: [new Paragraph([$this->pngImage()])],
            footer: [new Paragraph([$this->pngImage()])],
        ));

        ['parts' => $parts, 'rels' => $rels] = $this->unpack((new Word2007Writer)->write($doc));

        foreach ($parts as $name => $xml) {
            if (! preg_match('#^word/(document|header\d+|footer\d+)\.xml$#', $name)) {
                continue;
            }
            preg_match_all('/r:(?:embed|id)="([^"]+)"/', $xml, $used);
            if ($used[1] === []) {
                continue;
            }
            $relsXml = $rels['word/_rels/'.basename($name).'.rels'] ?? '';
            foreach (array_unique($used[1]) as $rId) {
                self::assertStringContainsString('Id="'.$rId.'"', $relsXml, "{$name}: ссылка {$rId} нигде не объявлена");
            }
        }
    }

    #[Test]
    public function every_drawing_carries_its_own_number(): void
    {
        // Номер рисунка уникален во всём документе: два одинаковых Word
        // считает поводом объявить файл повреждённым. Раньше номер брался от
        // номера ссылки, а с собственной нумерацией у каждой части картинка
        // шапки и первая картинка тела получали один и тот же rId1.
        $doc = new Document(new Section(
            body: [new Paragraph([$this->pngImage()]), new Paragraph([$this->pngImage()])],
            header: [new Paragraph([$this->pngImage()])],
            footer: [new Paragraph([$this->pngImage()])],
        ));

        ['parts' => $parts] = $this->unpack((new Word2007Writer)->write($doc));

        $ids = [];
        foreach ($parts as $name => $xml) {
            if (preg_match('#^word/(document|header\d+|footer\d+)\.xml$#', $name) !== 1) {
                continue;
            }
            preg_match_all('/<wp:docPr id="(\d+)"/', $xml, $m);
            $ids = [...$ids, ...$m[1]];
        }

        self::assertCount(4, $ids, 'не все рисунки напечатались');
        self::assertSame($ids, array_values(array_unique($ids)), 'номера рисунков повторяются');
    }
}
