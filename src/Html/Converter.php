<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Html;

use Dskripchenko\PhpDocx\Document;
use Dskripchenko\PhpDocx\Exception\DocxException;
use Dskripchenko\PhpDocx\Section;
use Dskripchenko\PhpDocx\Style\PageSetup;

/**
 * Конвертер HTML → Document. Phase 1: skeleton (stub).
 *
 * Полная реализация (Phase 2+): парсинг через DOMDocument::loadHTML (lenient),
 * обход DOM-tree, маппинг тегов в элементы, парсинг inline `style="..."`
 * через простой regex. CSS-классы — caller'у решать (inline'ить upstream).
 */
final class Converter
{
    public function __construct(
        private readonly PageSetup $defaultPageSetup = new PageSetup,
    ) {}

    /**
     * Парсит HTML body fragment в Document.
     */
    public function fromHtml(
        string $body,
        ?string $header = null,
        ?string $footer = null,
        ?PageSetup $pageSetup = null,
        ?string $watermarkText = null,
    ): Document {
        // TODO Phase 2: DOM traversal + element mapping.
        throw new DocxException('Html\\Converter::fromHtml() not implemented yet (Phase 2).');
    }
}
