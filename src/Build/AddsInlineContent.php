<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Build;

use Dskripchenko\PhpDocx\Element\Bookmark;
use Dskripchenko\PhpDocx\Element\Field;
use Dskripchenko\PhpDocx\Element\Hyperlink;
use Dskripchenko\PhpDocx\Element\Image;
use Dskripchenko\PhpDocx\Element\ImageFormat;
use Dskripchenko\PhpDocx\Element\InlineElement;
use Dskripchenko\PhpDocx\Element\LineBreak;
use Dskripchenko\PhpDocx\Element\Run;
use Dskripchenko\PhpDocx\Style\RunStyle;

/**
 * Shared trait для builder'ов, которые накапливают inline-content:
 *  - ParagraphBuilder
 *  - ListItemBuilder
 *
 * Реализует full inline-API: text/bold/italic/underline/strike/sup/sub/
 * lineBreak/styled/link/internalLink/bookmark/image helpers/fields.
 */
trait AddsInlineContent
{
    /** @var list<InlineElement> */
    private array $children = [];

    private RunStyle $defaultRunStyle;

    public function add(InlineElement $element): self
    {
        $this->children[] = $element;

        return $this;
    }

    public function text(string $text, ?RunStyle $style = null): self
    {
        $this->children[] = new Run($text, $style ?? $this->defaultRunStyle);

        return $this;
    }

    public function bold(string $text): self
    {
        return $this->text($text, $this->defaultRunStyle->withBold());
    }

    public function italic(string $text): self
    {
        return $this->text($text, $this->defaultRunStyle->withItalic());
    }

    public function underline(string $text): self
    {
        return $this->text($text, $this->defaultRunStyle->withUnderline());
    }

    public function strike(string $text): self
    {
        return $this->text($text, $this->defaultRunStyle->withStrikethrough());
    }

    public function sup(string $text): self
    {
        return $this->text($text, $this->defaultRunStyle->withSuperscript());
    }

    public function sub(string $text): self
    {
        return $this->text($text, $this->defaultRunStyle->withSubscript());
    }

    public function lineBreak(): self
    {
        $this->children[] = new LineBreak;

        return $this;
    }

    /**
     * Run с custom-style через RunStyleBuilder closure.
     *
     *   ->styled('Important', fn($s) => $s->color('ff0000')->bold())
     */
    public function styled(string $text, callable $styleCallback): self
    {
        $builder = RunStyleBuilder::from($this->defaultRunStyle);
        $styleCallback($builder);

        return $this->text($text, $builder->build());
    }

    /**
     * Меняет default RunStyle для последующих text/bold/etc — для
     * установки базового font/size на весь параграф/item.
     */
    public function withRunStyle(RunStyle $style): self
    {
        $this->defaultRunStyle = $style;

        return $this;
    }

    // ─────────── Hyperlinks ──────────────────────────────────────────────

    /**
     * Внешняя ссылка. Content — строка (short-form Run) или closure,
     * собирающая inline content через временный ParagraphBuilder.
     *
     * @param  string|callable(ParagraphBuilder): void  $textOrBuilder
     */
    public function link(string $href, string|callable $textOrBuilder): self
    {
        $children = $this->collectInlines($textOrBuilder);
        $this->children[] = new Hyperlink(href: $href, children: $children);

        return $this;
    }

    /**
     * Внутренняя ссылка на bookmark с заданным name.
     *
     * @param  string|callable(ParagraphBuilder): void  $textOrBuilder
     */
    public function internalLink(string $anchor, string|callable $textOrBuilder): self
    {
        $children = $this->collectInlines($textOrBuilder);
        $this->children[] = Hyperlink::internal($anchor, $children);

        return $this;
    }

    /**
     * Bookmark anchor. Обёртывает content (или ставит пустую метку если
     * content = '').
     *
     * @param  string|callable(ParagraphBuilder): void  $textOrBuilder
     */
    public function bookmark(string $name, string|callable $textOrBuilder = ''): self
    {
        $children = $textOrBuilder === '' ? [] : $this->collectInlines($textOrBuilder);
        $this->children[] = new Bookmark($name, $children);

        return $this;
    }

    // ─────────── Fields ──────────────────────────────────────────────────

    public function pageNumber(): self
    {
        $this->children[] = Field::page($this->defaultRunStyle);

        return $this;
    }

    public function totalPages(): self
    {
        $this->children[] = Field::pageTotal($this->defaultRunStyle);

        return $this;
    }

    public function currentDate(string $format = 'dd.MM.yyyy'): self
    {
        $this->children[] = Field::date($format, $this->defaultRunStyle);

        return $this;
    }

    public function currentTime(string $format = 'HH:mm'): self
    {
        $this->children[] = Field::time($format, $this->defaultRunStyle);

        return $this;
    }

    /**
     * MERGEFIELD placeholder для дальнейшего mail-merge'а.
     * Сериализуется как `<w:fldSimple w:instr="MERGEFIELD Name \\* MERGEFORMAT">`.
     */
    public function mergeField(string $name): self
    {
        $this->children[] = new Field(
            instruction: 'MERGEFIELD '.$name.' \\* MERGEFORMAT',
            style: $this->defaultRunStyle,
        );

        return $this;
    }

    // ─────────── Images (inline) ─────────────────────────────────────────

    public function image(Image $image): self
    {
        $this->children[] = $image;

        return $this;
    }

    /**
     * Из bytes — caller знает формат и размеры в пикселях.
     */
    public function imageFromBytes(
        string $binary,
        ImageFormat $format,
        int $widthPx,
        int $heightPx,
        ?string $altText = null,
    ): self {
        $this->children[] = Image::fromPx($binary, $format, $widthPx, $heightPx, $altText);

        return $this;
    }

    /**
     * Из data:image/...;base64,... URL — формат и размеры авто-детектятся.
     */
    public function imageFromDataUrl(
        string $dataUrl,
        ?int $widthPx = null,
        ?int $heightPx = null,
        ?string $altText = null,
    ): self {
        if (preg_match('/^data:image\/(\w+);base64,(.+)$/s', $dataUrl, $m) !== 1) {
            return $this; // silently skip
        }
        $format = match (strtolower($m[1])) {
            'png' => ImageFormat::Png,
            'jpg', 'jpeg' => ImageFormat::Jpeg,
            'gif' => ImageFormat::Gif,
            'bmp' => ImageFormat::Bmp,
            default => null,
        };
        if ($format === null) {
            return $this;
        }
        $binary = base64_decode($m[2], true);
        if ($binary === false) {
            return $this;
        }

        return $this->ingestImage($binary, $format, $widthPx, $heightPx, $altText);
    }

    /**
     * Из файла — формат по расширению/magic-bytes, размеры из binary
     * (если не переданы).
     */
    public function imageFromFile(
        string $path,
        ?int $widthPx = null,
        ?int $heightPx = null,
        ?string $altText = null,
    ): self {
        if (! is_readable($path)) {
            throw new \InvalidArgumentException('Image file not readable: '.$path);
        }
        $binary = (string) file_get_contents($path);
        $format = $this->detectFormatFromPath($path) ?? $this->detectFormatFromBinary($binary);
        if ($format === null) {
            throw new \InvalidArgumentException('Unsupported image format: '.$path);
        }

        return $this->ingestImage($binary, $format, $widthPx, $heightPx, $altText);
    }

    /**
     * Helper для image*-методов: размеры auto-detect если null.
     */
    private function ingestImage(
        string $binary,
        ImageFormat $format,
        ?int $widthPx,
        ?int $heightPx,
        ?string $altText,
    ): self {
        if ($widthPx === null || $heightPx === null) {
            $info = @getimagesizefromstring($binary);
            if ($info !== false) {
                $widthPx ??= (int) $info[0];
                $heightPx ??= (int) $info[1];
            }
        }
        $widthPx = max(1, $widthPx ?? 100);
        $heightPx = max(1, $heightPx ?? 100);
        $this->children[] = Image::fromPx($binary, $format, $widthPx, $heightPx, $altText);

        return $this;
    }

    private function detectFormatFromPath(string $path): ?ImageFormat
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($ext) {
            'png' => ImageFormat::Png,
            'jpg', 'jpeg' => ImageFormat::Jpeg,
            'gif' => ImageFormat::Gif,
            'bmp' => ImageFormat::Bmp,
            default => null,
        };
    }

    private function detectFormatFromBinary(string $bytes): ?ImageFormat
    {
        if (str_starts_with($bytes, "\x89PNG\r\n\x1A\n")) {
            return ImageFormat::Png;
        }
        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return ImageFormat::Jpeg;
        }
        if (str_starts_with($bytes, 'GIF87a') || str_starts_with($bytes, 'GIF89a')) {
            return ImageFormat::Gif;
        }
        if (str_starts_with($bytes, 'BM')) {
            return ImageFormat::Bmp;
        }

        return null;
    }

    /**
     * Создаёт временный ParagraphBuilder, прогоняет callback, забирает
     * inline children. Используется в link/internalLink/bookmark для
     * rich-content.
     *
     * @param  string|callable(ParagraphBuilder): void  $textOrBuilder
     * @return list<InlineElement>
     */
    private function collectInlines(string|callable $textOrBuilder): array
    {
        if (is_string($textOrBuilder)) {
            return [new Run($textOrBuilder, $this->defaultRunStyle)];
        }
        $temp = new ParagraphBuilder(defaultRunStyle: $this->defaultRunStyle);
        $textOrBuilder($temp);

        return $temp->buildInlines();
    }
}
