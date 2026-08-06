<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Tests\Writer;

use Dskripchenko\PhpDocx\Document;
use Dskripchenko\PhpDocx\Element\Paragraph;
use Dskripchenko\PhpDocx\Element\Run;
use Dskripchenko\PhpDocx\Section;
use Dskripchenko\PhpDocx\Style\CoreProperties;
use Dskripchenko\PhpDocx\Writer\Word2007Writer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `docProps/core.xml` — то, что Word показывает в File → Info, а проводник
 * Windows в колонках «Название» и «Авторы». Без этой части документ
 * открывается анонимным: ни автора, ни заголовка.
 *
 * Проверяется не только наличие текста, но и обвязка пакета: часть,
 * объявленная не в том месте, для Word не существует, а с неверным типом
 * даты — повод предложить восстановление документа.
 */
final class CorePropertiesTest extends TestCase
{
    #[Test]
    public function writesCorePropertiesWithPackageWiring(): void
    {
        $docx = $this->write(new CoreProperties(
            title: 'Счёт № 42',
            creator: 'ООО «Ромашка»',
            keywords: 'счёт, оплата',
            created: new \DateTimeImmutable('2026-08-06 09:00:00', new \DateTimeZone('UTC')),
        ));

        $core = $this->part($docx, 'docProps/core.xml');
        self::assertStringContainsString('<dc:title>Счёт № 42</dc:title>', $core);
        self::assertStringContainsString('<dc:creator>ООО «Ромашка»</dc:creator>', $core);
        self::assertStringContainsString('<cp:keywords>счёт, оплата</cp:keywords>', $core);

        // Дата обязана нести xsi:type, иначе Word предлагает восстановить
        // документ вместо того, чтобы его открыть.
        self::assertStringContainsString(
            '<dcterms:created xsi:type="dcterms:W3CDTF">2026-08-06T09:00:00Z</dcterms:created>',
            $core,
        );

        // Часть должна быть объявлена в типах содержимого и связана из
        // КОРНЕВЫХ rels: свойства принадлежат пакету, а не document.xml.
        self::assertStringContainsString(
            '<Override PartName="/docProps/core.xml"',
            $this->part($docx, '[Content_Types].xml'),
        );
        self::assertStringContainsString(
            'Target="docProps/core.xml"',
            $this->part($docx, '_rels/.rels'),
        );
        self::assertStringNotContainsString(
            'docProps/core.xml',
            $this->part($docx, 'word/_rels/document.xml.rels'),
        );
    }

    #[Test]
    public function omitsThePartEntirelyWhenNothingIsSet(): void
    {
        $withoutProperties = $this->write(null);
        $withEmptyProperties = $this->write(new CoreProperties);

        foreach ([$withoutProperties, $withEmptyProperties] as $docx) {
            self::assertNull($this->partOrNull($docx, 'docProps/core.xml'));
            self::assertStringNotContainsString('docProps/core.xml', $this->part($docx, '_rels/.rels'));
        }
    }

    #[Test]
    public function xmlIsWellFormed(): void
    {
        $core = $this->part(
            $this->write(new CoreProperties(title: 'A & B <tag>', creator: "Кавычки \"и\" 'разные'")),
            'docProps/core.xml',
        );

        $doc = new \DOMDocument;
        self::assertTrue($doc->loadXML($core), 'core.xml не разбирается как XML');
    }

    private function write(?CoreProperties $properties): string
    {
        return (new Word2007Writer)->write(new Document(
            new Section(body: [new Paragraph([new Run('текст')])]),
            coreProperties: $properties,
        ));
    }

    private function part(string $docx, string $name): string
    {
        $content = $this->partOrNull($docx, $name);
        self::assertNotNull($content, "в пакете нет части {$name}");

        return $content;
    }

    private function partOrNull(string $docx, string $name): ?string
    {
        $file = tempnam(sys_get_temp_dir(), 'docx-test-');
        file_put_contents($file, $docx);

        $zip = new \ZipArchive;
        $zip->open($file);
        $content = $zip->getFromName($name);
        $zip->close();
        @unlink($file);

        return $content === false ? null : $content;
    }
}
