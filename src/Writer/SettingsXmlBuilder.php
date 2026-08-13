<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Writer;

/**
 * Generates `word/settings.xml` with the document-level settings.
 *
 * The set is minimal — only `<w:evenAndOddHeaders/>`, and only when the
 * even-page headers or footers are on. The other settings (zoom,
 * autoHyphenation, defaultTabStop and so on) are not covered yet.
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
