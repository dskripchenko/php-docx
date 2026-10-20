<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Tests\Writer;

use Dskripchenko\PhpDocx\Document;
use Dskripchenko\PhpDocx\Element\Field;
use Dskripchenko\PhpDocx\Element\Image;
use Dskripchenko\PhpDocx\Element\Paragraph;
use Dskripchenko\PhpDocx\Element\Run;
use Dskripchenko\PhpDocx\Element\ImageFormat;
use Dskripchenko\PhpDocx\Section;
use Dskripchenko\PhpDocx\Style\CoreProperties;
use Dskripchenko\PhpDocx\Writer\Word2007Writer;
use Dskripchenko\PhpDocx\Writer\ZipTimestamp;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The same document has to give the same bytes, whenever and wherever it is
 * written: a cache key, a checksum or a diff of generated files is useless
 * otherwise.
 *
 * The only thing in the package that changed between writes was the time
 * ZipArchive put on every entry. The tests read that time straight from the
 * archive's headers — not through `ZipArchive::statName()`, which converts it
 * through the local time zone and would hide exactly the difference that
 * matters.
 */
final class DeterministicOutputTest extends TestCase
{
    /** 1980-01-01 00:00:00 in MS-DOS time and date words. */
    private const DOS_EPOCH = [0x0000, 0x0021];

    #[Test]
    public function everyEntryCarriesTheDosEpochWithoutCoreProperties(): void
    {
        $docx = (new Word2007Writer)->write($this->document());

        $stamps = $this->entryStamps($docx);
        self::assertGreaterThan(5, count($stamps), 'header, media and rels parts are all stamped');
        foreach ($stamps as $name => $stamp) {
            self::assertSame([self::DOS_EPOCH, self::DOS_EPOCH], $stamp, $name);
        }
    }

    #[Test]
    public function entriesTakeTheModifiedTimeAsUtcWallClock(): void
    {
        // 11:30:44 in Moscow is 08:30:44 UTC; DOS keeps seconds in steps of 2.
        $modified = new \DateTimeImmutable('2026-08-06 11:30:44', new \DateTimeZone('Europe/Moscow'));
        $docx = (new Word2007Writer)->write($this->document(new CoreProperties(title: 'T', modified: $modified)));

        $expected = [(8 << 11) | (30 << 5) | 22, ((2026 - 1980) << 9) | (8 << 5) | 6];
        foreach ($this->entryStamps($docx) as $name => $stamp) {
            self::assertSame([$expected, $expected], $stamp, $name);
        }
    }

    #[Test]
    public function aModifiedTimeBeforeDosTimeIsClampedTo1980(): void
    {
        $docx = (new Word2007Writer)->write($this->document(new CoreProperties(
            title: 'T',
            modified: new \DateTimeImmutable('1970-01-01 00:00:00', new \DateTimeZone('UTC')),
        )));

        foreach ($this->entryStamps($docx) as $name => $stamp) {
            self::assertSame([self::DOS_EPOCH, self::DOS_EPOCH], $stamp, $name);
        }
    }

    #[Test]
    public function twoWritesGiveIdenticalBytes(): void
    {
        $writer = new Word2007Writer;

        self::assertSame($writer->write($this->document()), $writer->write($this->document()));
    }

    #[Test]
    public function theStampedArchiveStillPassesConsistencyChecks(): void
    {
        $docx = (new Word2007Writer)->write($this->document());

        $tmp = (string) tempnam(sys_get_temp_dir(), 'docx-test-');
        file_put_contents($tmp, $docx);
        try {
            $zip = new \ZipArchive;
            self::assertTrue($zip->open($tmp, \ZipArchive::CHECKCONS));
            self::assertStringContainsString('Hello', (string) $zip->getFromName('word/document.xml'));
            $zip->close();
        } finally {
            @unlink($tmp);
        }
    }

    #[Test]
    public function anArchiveItCannotParseIsReturnedUnchanged(): void
    {
        self::assertSame('not a zip', ZipTimestamp::apply('not a zip'));
    }

    private function document(?CoreProperties $core = null): Document
    {
        // A 1×1 PNG, so the package has a media part and a part-level rels file.
        $png = (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
        );

        return new Document(
            new Section(
                body: [new Paragraph([new Run('Hello')])],
                header: [new Paragraph([Field::page(), new Image($png, ImageFormat::Png, 9525, 9525)])],
            ),
            coreProperties: $core,
        );
    }

    /**
     * The DOS [time, date] of every entry, from its central directory record
     * and from its local file header.
     *
     * @return array<string, array{array{int, int}, array{int, int}}>
     */
    private function entryStamps(string $zip): array
    {
        $end = unpack('ventries/x4/Voffset', $zip, strlen($zip) - 22 + 10);
        self::assertIsArray($end);

        $stamps = [];
        $pos = $end['offset'];
        for ($i = 0; $i < $end['entries']; $i++) {
            $cd = unpack('vtime/vdate/x12/vnameLength/vextraLength/vcommentLength/x8/VlocalOffset', $zip, $pos + 12);
            self::assertIsArray($cd);
            $name = substr($zip, $pos + 46, $cd['nameLength']);
            $local = unpack('vtime/vdate', $zip, $cd['localOffset'] + 10);
            self::assertIsArray($local);

            $stamps[$name] = [[$cd['time'], $cd['date']], [$local['time'], $local['date']]];
            $pos += 46 + $cd['nameLength'] + $cd['extraLength'] + $cd['commentLength'];
        }

        return $stamps;
    }
}
