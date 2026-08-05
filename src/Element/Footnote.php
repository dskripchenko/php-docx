<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Element;

/**
 * Сноска — знак в строке и её текст внизу полосы.
 *
 * В контейнере это две разные части: `w:footnoteReference` в тексте и сам
 * текст в `word/footnotes.xml`. Здесь они уже сведены: потребителю нужна
 * сноска целиком, а не ссылка на неё.
 */
final readonly class Footnote implements InlineElement
{
    public function __construct(public string $content) {}
}
