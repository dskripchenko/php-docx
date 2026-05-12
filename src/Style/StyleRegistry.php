<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Style;

/**
 * Registry именованных стилей (Heading1..6, ListParagraph, custom).
 *
 * Caller передаёт в Word2007Writer; если не передан — используется
 * `StyleRegistry::defaults()`. Если caller хочет полностью свои стили,
 * может начать с пустого `new StyleRegistry` и регистрировать только нужное.
 */
final class StyleRegistry
{
    /** @var array<string, array{run: RunStyle, paragraph: ParagraphStyle, type: 'paragraph'|'character'}> */
    private array $styles = [];

    /**
     * Регистрирует heading-стиль уровня 1..6.
     * Mapping: paragraph style с w:styleId="Heading{level}".
     */
    public function heading(int $level, RunStyle $runStyle, ?ParagraphStyle $paragraphStyle = null): self
    {
        if ($level < 1 || $level > 6) {
            throw new \InvalidArgumentException('heading level must be 1..6, got '.$level);
        }
        $this->styles['Heading'.$level] = [
            'run' => $runStyle,
            'paragraph' => $paragraphStyle ?? new ParagraphStyle,
            'type' => 'paragraph',
        ];

        return $this;
    }

    public function paragraph(string $styleId, RunStyle $runStyle, ParagraphStyle $paragraphStyle): self
    {
        $this->styles[$styleId] = [
            'run' => $runStyle,
            'paragraph' => $paragraphStyle,
            'type' => 'paragraph',
        ];

        return $this;
    }

    /**
     * Дефолтный набор Heading1..6 + ListParagraph.
     */
    public static function defaults(): self
    {
        $r = new self;
        // Размеры в half-points: 44=22pt, 36=18pt, 28=14pt, 24=12pt, 22=11pt, 20=10pt
        $sizes = [1 => 44, 2 => 36, 3 => 28, 4 => 24, 5 => 22, 6 => 20];
        $colors = [1 => '0f172a', 2 => '0f172a', 3 => '1f2937', 4 => '1f2937', 5 => '374151', 6 => '374151'];
        foreach ($sizes as $lvl => $size) {
            $r->heading(
                $lvl,
                new RunStyle(sizeHalfPoints: $size, color: $colors[$lvl], bold: true),
                new ParagraphStyle(
                    spaceBeforeTwips: 240 - ($lvl * 20),
                    spaceAfterTwips: 120 - ($lvl * 10),
                ),
            );
        }
        // ListParagraph — для elements c bullet/numbering.
        $r->paragraph(
            'ListParagraph',
            new RunStyle,
            new ParagraphStyle(indentLeftTwips: 720),
        );

        return $r;
    }

    /**
     * @return array<string, array{run: RunStyle, paragraph: ParagraphStyle, type: 'paragraph'|'character'}>
     */
    public function all(): array
    {
        return $this->styles;
    }

    public function isEmpty(): bool
    {
        return $this->styles === [];
    }
}
