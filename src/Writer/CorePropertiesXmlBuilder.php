<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Writer;

use Dskripchenko\PhpDocx\Style\CoreProperties;

/**
 * Builds `docProps/core.xml` — the Open Packaging Conventions core
 * properties (ECMA-376 Part 2, §11).
 *
 * Dates are written as W3CDTF in UTC with the `xsi:type` attribute the
 * schema requires; Word rejects the part outright when the type is
 * missing, and the document then opens with a repair prompt.
 */
final class CorePropertiesXmlBuilder
{
    public function __construct(private readonly CoreProperties $properties) {}

    public function render(): string
    {
        $p = $this->properties;

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<cp:coreProperties'
            .' xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"'
            .' xmlns:dc="http://purl.org/dc/elements/1.1/"'
            .' xmlns:dcterms="http://purl.org/dc/terms/"'
            .' xmlns:dcmitype="http://purl.org/dc/dcmitype/"'
            .' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">';

        $xml .= $this->element('dc:title', $p->title);
        $xml .= $this->element('dc:subject', $p->subject);
        $xml .= $this->element('dc:creator', $p->creator);
        $xml .= $this->element('cp:keywords', $p->keywords);
        $xml .= $this->element('dc:description', $p->description);
        $xml .= $this->element('cp:lastModifiedBy', $p->lastModifiedBy);
        $xml .= $this->date('dcterms:created', $p->created);
        $xml .= $this->date('dcterms:modified', $p->modified);
        $xml .= $this->element('cp:category', $p->category);

        return $xml.'</cp:coreProperties>';
    }

    private function element(string $name, ?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return '<'.$name.'>'.XmlEscape::text($value).'</'.$name.'>';
    }

    private function date(string $name, ?\DateTimeInterface $value): string
    {
        if ($value === null) {
            return '';
        }

        $utc = (new \DateTimeImmutable('@'.$value->getTimestamp()))->setTimezone(new \DateTimeZone('UTC'));

        return '<'.$name.' xsi:type="dcterms:W3CDTF">'.$utc->format('Y-m-d\TH:i:s\Z').'</'.$name.'>';
    }
}
