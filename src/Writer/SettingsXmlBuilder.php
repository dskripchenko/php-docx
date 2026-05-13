<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Writer;

/**
 * Генерирует `word/settings.xml` с document-level settings.
 *
 * Минимальный set — только `<w:evenAndOddHeaders/>` если включены
 * even-headers/footers. Остальные настройки (zoom, autoHyphenation,
 * defaultTabStop и т.д.) пока не покрываем.
 */
final class SettingsXmlBuilder
{
    public function __construct(
        private readonly bool $evenAndOddHeaders = false,
    ) {}

    public function render(): string
    {
        $body = '';
        if ($this->evenAndOddHeaders) {
            $body .= '<w:evenAndOddHeaders/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:settings xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .$body
            .'</w:settings>';
    }
}
