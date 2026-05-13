<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Build;

use Dskripchenko\PhpDocx\Element\InlineElement;
use Dskripchenko\PhpDocx\Element\LineBreak;
use Dskripchenko\PhpDocx\Element\ListItem;
use Dskripchenko\PhpDocx\Element\ListNode;
use Dskripchenko\PhpDocx\Element\Run;
use Dskripchenko\PhpDocx\Style\RunStyle;

/**
 * Fluent builder для отдельного `<li>`. Inline-API почти совпадает с
 * ParagraphBuilder (text/bold/italic/...), плюс optional nested list.
 *
 * Обычно создаётся внутри ListBuilder::item() — caller'у напрямую
 * не нужен в short-form (`->item('text')`).
 */
final class ListItemBuilder
{
    /** @var list<InlineElement> */
    private array $children = [];

    private ?ListNode $nested = null;

    private RunStyle $defaultRunStyle;

    public function __construct(?RunStyle $defaultRunStyle = null)
    {
        $this->defaultRunStyle = $defaultRunStyle ?? new RunStyle;
    }

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

    public function lineBreak(): self
    {
        $this->children[] = new LineBreak;

        return $this;
    }

    /**
     * Run с custom RunStyle через RunStyleBuilder closure.
     *
     *   ->styled('Important', fn($s) => $s->color('ff0000')->bold())
     */
    public function styled(string $text, callable $styleCallback): self
    {
        $builder = RunStyleBuilder::from($this->defaultRunStyle);
        $styleCallback($builder);

        return $this->text($text, $builder->build());
    }

    public function withRunStyle(RunStyle $style): self
    {
        $this->defaultRunStyle = $style;

        return $this;
    }

    public function withNested(?ListNode $list): self
    {
        $this->nested = $list;

        return $this;
    }

    public function build(): ListItem
    {
        return new ListItem(
            children: $this->children,
            nestedList: $this->nested,
        );
    }
}
