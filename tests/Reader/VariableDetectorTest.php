<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Tests\Reader;

use Dskripchenko\PhpDocx\Html\Converter;
use Dskripchenko\PhpDocx\Reader\DetectedVariable;
use Dskripchenko\PhpDocx\Reader\DocxPackageReader;
use Dskripchenko\PhpDocx\Reader\OoxmlNs;
use Dskripchenko\PhpDocx\Reader\VariableDetector;
use Dskripchenko\PhpDocx\Reader\VariableSource;
use Dskripchenko\PhpDocx\Writer\Word2007Writer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class VariableDetectorTest extends TestCase
{
    #[Test]
    public function detects_mergefield_in_fldSimple(): void
    {
        $pkg = $this->loadPackage(
            '<w:p>'
            .'<w:r><w:t xml:space="preserve">Name: </w:t></w:r>'
            .'<w:fldSimple w:instr="MERGEFIELD CustomerName \\* MERGEFORMAT">'
            .'<w:r><w:t>«CustomerName»</w:t></w:r>'
            .'</w:fldSimple>'
            .'</w:p>'
        );
        $vars = (new VariableDetector)->detect($pkg);

        self::assertCount(1, $vars);
        self::assertSame('CustomerName', $vars[0]->name);
        self::assertSame(VariableSource::MergeField, $vars[0]->source);
        self::assertSame('«CustomerName»', $vars[0]->sampleValue);
    }

    #[Test]
    public function detects_complex_field_mergefield(): void
    {
        $pkg = $this->loadPackage(
            '<w:p>'
            .'<w:r><w:fldChar w:fldCharType="begin"/></w:r>'
            .'<w:r><w:instrText>MERGEFIELD OrderId</w:instrText></w:r>'
            .'<w:r><w:fldChar w:fldCharType="separate"/></w:r>'
            .'<w:r><w:t>ORD-001</w:t></w:r>'
            .'<w:r><w:fldChar w:fldCharType="end"/></w:r>'
            .'</w:p>'
        );
        $vars = (new VariableDetector)->detect($pkg);
        self::assertCount(1, $vars);
        self::assertSame('OrderId', $vars[0]->name);
        self::assertSame('ORD-001', $vars[0]->sampleValue);
    }

    #[Test]
    public function detects_sdt_content_control_via_tag(): void
    {
        $pkg = $this->loadPackage(
            '<w:p>'
            .'<w:sdt>'
            .'<w:sdtPr><w:tag w:val="CustomerName"/><w:alias w:val="Customer"/></w:sdtPr>'
            .'<w:sdtContent><w:r><w:t>Acme</w:t></w:r></w:sdtContent>'
            .'</w:sdt>'
            .'</w:p>'
        );
        $vars = (new VariableDetector)->detect($pkg);

        $sdtVars = array_values(array_filter($vars, fn (DetectedVariable $v) => $v->source === VariableSource::ContentControl));
        self::assertCount(1, $sdtVars);
        self::assertSame('CustomerName', $sdtVars[0]->name);
        self::assertSame('Acme', $sdtVars[0]->sampleValue);
    }

    #[Test]
    public function sdt_falls_back_to_alias_if_no_tag(): void
    {
        $pkg = $this->loadPackage(
            '<w:p>'
            .'<w:sdt>'
            .'<w:sdtPr><w:alias w:val="MyAlias"/></w:sdtPr>'
            .'<w:sdtContent><w:r><w:t>X</w:t></w:r></w:sdtContent>'
            .'</w:sdt>'
            .'</w:p>'
        );
        $vars = (new VariableDetector)->detect($pkg);
        $sdtVars = array_values(array_filter($vars, fn (DetectedVariable $v) => $v->source === VariableSource::ContentControl));
        self::assertCount(1, $sdtVars);
        self::assertSame('MyAlias', $sdtVars[0]->name);
    }

    #[Test]
    public function ignores_watermark_sdt(): void
    {
        $pkg = $this->loadPackage(
            '<w:p>'
            .'<w:sdt>'
            .'<w:sdtPr><w:tag w:val="Watermark"/></w:sdtPr>'
            .'<w:sdtContent/>'
            .'</w:sdt>'
            .'</w:p>'
        );
        $vars = (new VariableDetector)->detect($pkg);
        self::assertSame([], $vars);
    }

    #[Test]
    public function detects_text_patterns(): void
    {
        $pkg = $this->loadPackage(
            '<w:p><w:r><w:t xml:space="preserve">Hello {{name}}, your order ${orderId} is %status%</w:t></w:r></w:p>'
        );
        $vars = (new VariableDetector)->detect($pkg);

        $names = array_map(fn (DetectedVariable $v) => $v->name, $vars);
        self::assertContains('name', $names);
        self::assertContains('orderId', $names);
        self::assertContains('status', $names);
    }

    #[Test]
    public function text_pattern_spread_across_runs(): void
    {
        // Word часто разбивает текст на несколько runs при редактировании.
        $pkg = $this->loadPackage(
            '<w:p>'
            .'<w:r><w:t xml:space="preserve">Hello {{</w:t></w:r>'
            .'<w:r><w:t xml:space="preserve">user.name</w:t></w:r>'
            .'<w:r><w:t xml:space="preserve">}}</w:t></w:r>'
            .'</w:p>'
        );
        $vars = (new VariableDetector)->detect($pkg);
        $patternVars = array_values(array_filter($vars, fn (DetectedVariable $v) => $v->source === VariableSource::TextPattern));
        self::assertCount(1, $patternVars);
        self::assertSame('user.name', $patternVars[0]->name);
    }

    #[Test]
    public function deduplicates_by_source_and_name(): void
    {
        $pkg = $this->loadPackage(
            '<w:p><w:r><w:t>{{x}}</w:t></w:r></w:p>'
            .'<w:p><w:r><w:t>{{x}}</w:t></w:r></w:p>'
            .'<w:p><w:r><w:t>{{x}}</w:t></w:r></w:p>'
        );
        $vars = (new VariableDetector)->detect($pkg);
        self::assertCount(1, $vars);
    }

    #[Test]
    public function custom_patterns_override_defaults(): void
    {
        $pkg = $this->loadPackage(
            '<w:p><w:r><w:t>Hello [[name]]!</w:t></w:r></w:p>'
        );
        $detector = new VariableDetector(['/\[\[([a-zA-Z_]+)\]\]/']);
        $vars = $detector->detect($pkg);
        self::assertCount(1, $vars);
        self::assertSame('name', $vars[0]->name);
    }

    #[Test]
    public function detects_across_header_and_body(): void
    {
        $bytes = (new Word2007Writer)->write((new Converter)->fromHtml(
            body: '<p>Hello {{customer}}</p>',
            header: '<p>{{company}}</p>',
        ));
        $pkg = (new DocxPackageReader)->read($bytes);
        $vars = (new VariableDetector)->detect($pkg);
        $names = array_map(fn (DetectedVariable $v) => $v->name, $vars);
        self::assertContains('customer', $names);
        self::assertContains('company', $names);
    }

    #[Test]
    public function false_positive_text_not_matching_pattern(): void
    {
        $pkg = $this->loadPackage(
            '<w:p><w:r><w:t>This text has braces {but not the right kind} and 100%</w:t></w:r></w:p>'
        );
        $vars = (new VariableDetector)->detect($pkg);
        self::assertSame([], $vars);
    }

    private function loadPackage(string $bodyInner): \Dskripchenko\PhpDocx\Reader\DocxPackage
    {
        // Создаём DocxPackage напрямую с custom body для unit-тестов.
        $doc = new \DOMDocument;
        $doc->loadXML(
            '<w:document xmlns:w="'.OoxmlNs::W.'">'
            .'<w:body>'.$bodyInner.'</w:body>'
            .'</w:document>'
        );

        return new \Dskripchenko\PhpDocx\Reader\DocxPackage(
            documentPartPath: 'word/document.xml',
            documentXml: $doc,
        );
    }
}
