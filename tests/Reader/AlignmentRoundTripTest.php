<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Tests\Reader;

use Dskripchenko\PhpDocx\Build\DocumentBuilder;
use Dskripchenko\PhpDocx\Reader\DocxReader;
use Dskripchenko\PhpDocx\Element\Paragraph;
use Dskripchenko\PhpDocx\Style\Alignment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The reader used to reference the non-existent Alignment::Both case, so
 * any document with justified text (`<w:jc w:val="both"/>` — the default
 * alignment of most formal documents) crashed with an undefined-constant
 * Error. Every enum case must survive the write → read round-trip.
 */
final class AlignmentRoundTripTest extends TestCase
{
    public static function alignments(): array
    {
        return [
            'start' => [Alignment::Start],
            'center' => [Alignment::Center],
            'end' => [Alignment::End],
            'justify (w:val=both)' => [Alignment::Justify],
            'distribute' => [Alignment::Distribute],
        ];
    }

    #[Test]
    #[DataProvider('alignments')]
    public function alignment_survives_the_round_trip(Alignment $alignment): void
    {
        $bytes = DocumentBuilder::new()
            ->paragraph(fn ($p) => $p->text('aligned text')->align($alignment))
            ->toBytes();

        $document = (new DocxReader)->read($bytes);

        $paragraph = null;
        foreach ($document->section->body as $block) {
            if ($block instanceof Paragraph) {
                $paragraph = $block;
                break;
            }
        }

        self::assertNotNull($paragraph, 'Round-tripped document must contain the paragraph');
        self::assertSame($alignment, $paragraph->style->alignment);
    }
}
