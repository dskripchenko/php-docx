<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Tests\Html;

use Dskripchenko\PhpDocx\Element\Paragraph;
use Dskripchenko\PhpDocx\Element\Run;
use Dskripchenko\PhpDocx\Html\Converter;
use Dskripchenko\PhpDocx\Style\Alignment;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FigureTest extends TestCase
{
    private const TINY_PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    #[Test]
    public function figcaption_renders_as_italic_centered_paragraph_after_image(): void
    {
        $html = '<figure>'
            .'<img src="'.self::TINY_PNG.'" width="100" height="50" alt="A"/>'
            .'<figcaption>Figure 1</figcaption>'
            .'</figure>';
        $doc = (new Converter)->fromHtml($html);
        $blocks = $doc->section->body;

        // There has to be at least one block with the image and one with the caption (Figure 1).
        $captionFound = false;
        foreach ($blocks as $b) {
            if (! $b instanceof Paragraph) {
                continue;
            }
            foreach ($b->children as $c) {
                if ($c instanceof Run && str_contains($c->text, 'Figure 1')) {
                    self::assertTrue($c->style->italic, 'caption должен быть italic');
                    self::assertSame(Alignment::Center, $b->style->alignment);
                    $captionFound = true;
                    break 2;
                }
            }
        }
        self::assertTrue($captionFound, 'caption paragraph должен быть в output');
    }

    #[Test]
    public function figure_without_figcaption_renders_only_image(): void
    {
        $html = '<figure>'
            .'<img src="'.self::TINY_PNG.'" width="100" height="50" alt="A"/>'
            .'</figure>';
        $doc = (new Converter)->fromHtml($html);
        $blocks = $doc->section->body;

        $hasCaption = false;
        foreach ($blocks as $b) {
            if ($b instanceof Paragraph) {
                foreach ($b->children as $c) {
                    if ($c instanceof Run && trim($c->text) !== '') {
                        $hasCaption = true;
                    }
                }
            }
        }
        self::assertFalse($hasCaption);
    }

    #[Test]
    public function empty_figcaption_is_ignored(): void
    {
        $html = '<figure>'
            .'<img src="'.self::TINY_PNG.'" width="100" height="50"/>'
            .'<figcaption></figcaption>'
            .'</figure>';
        $doc = (new Converter)->fromHtml($html);

        $found = false;
        foreach ($doc->section->body as $b) {
            if (! $b instanceof Paragraph) {
                continue;
            }
            foreach ($b->children as $c) {
                if ($c instanceof Run && trim($c->text) !== '') {
                    $found = true;
                }
            }
        }
        self::assertFalse($found);
    }
}
