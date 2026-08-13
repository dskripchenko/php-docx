<?php

declare(strict_types=1);

/**
 * Reader-fidelity harness. For every corpus document (build/corpus/*.docx
 * — externally produced by PHPWord, python-docx, LibreOffice, plus our
 * own writer; see scripts/corpus/):
 *
 *  1. GROUND TRUTH  — text lines extracted by python-docx (independent
 *     implementation), from body, tables, headers and footers.
 *  2. READ          — DocxReader must parse without crashing; the AST's
 *     HTML serialization must contain every ground-truth line
 *     (whitespace-normalized substring match).
 *  3. RE-EMIT       — Word2007Writer output from that AST must (a) pass
 *     the ECMA-376 Transitional XSD (xmllint), and (b) be readable by
 *     python-docx with the same ground-truth lines intact — a fully
 *     non-circular round trip.
 *  4. STABILITY     — reading the re-emitted file again must serialize to
 *     the same HTML (fixed point after one round trip).
 *
 * Writes summary-fidelity.md and regenerates docs/en/READER-FIDELITY.md.
 * Exits non-zero if any document fails any stage.
 *
 * Usage: php scripts/conformance/reader-fidelity.php [corpus-dir]
 * Env:   XMLLINT — xmllint override.
 */

use Dskripchenko\PhpDocx\Html\Serializer;
use Dskripchenko\PhpDocx\Reader\DocxReader;
use Dskripchenko\PhpDocx\Writer\Word2007Writer;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$root = dirname(__DIR__, 2);
$corpusDir = $argv[1] ?? $root.'/build/corpus';
$xsd = $root.'/.cache/ooxml-xsd/wml.xsd';
$xmllint = getenv('XMLLINT') ?: 'xmllint';

$files = array_merge(
    glob($corpusDir.'/*.docx') ?: [],
    glob($root.'/scripts/corpus/fixtures/*/*.docx') ?: [],
);
if ($files === []) {
    fwrite(STDERR, "No corpus documents in $corpusDir — run the scripts/corpus generators first.\n");
    exit(2);
}

/** @return list<string> */
function groundTruth(string $file): array
{
    exec(sprintf('python3 %s %s 2>/dev/null',
        escapeshellarg(__DIR__.'/extract-text.py'), escapeshellarg($file)), $lines, $code);
    if ($code !== 0) {
        throw new \RuntimeException('python-docx extraction failed');
    }

    return array_values(array_filter(array_map(trim(...), $lines), fn ($l) => $l !== ''));
}

function normalizeText(string $html): string
{
    // Compare WITHOUT whitespace: both tag boundaries («…д. 27</p><p>ИНН…»)
    // and the gluing of inline runs produced false losses, because the
    // whitespace seams differ between python-docx and the HTML serialization.
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    return (string) preg_replace('/\s+/u', '', $text);
}

/**
 * @param  list<string>  $lines
 * @return list<string>  Lines NOT found in $haystack.
 */
function missingLines(array $lines, string $haystack): array
{
    $norm = normalizeText($haystack);

    return array_values(array_filter(
        $lines,
        fn (string $line) => ! str_contains($norm, (string) preg_replace('/\s+/u', '', $line)),
    ));
}

function fullHtml(Serializer $serializer, \Dskripchenko\PhpDocx\Document $doc): string
{
    $imported = $serializer->serialize($doc);

    return $imported->headerHtml."\n".$imported->bodyHtml."\n".$imported->footerHtml;
}

$reader = new DocxReader;
$writer = new Word2007Writer;
$serializer = new Serializer;

$rows = [];
$fail = 0;
foreach ($files as $file) {
    $name = basename($file);
    $stages = ['read' => '—', 'coverage' => '—', 'reemit-xsd' => '—', 'reemit-read' => '—', 'stable' => '—'];
    $notes = [];

    try {
        $truth = groundTruth($file);

        // 2. READ + ground-truth coverage.
        $doc = $reader->read((string) file_get_contents($file));
        $stages['read'] = '✅';
        $html = fullHtml($serializer, $doc);
        $missing = missingLines($truth, $html);
        if ($missing === []) {
            $stages['coverage'] = sprintf('✅ %d/%d', count($truth), count($truth));
        } else {
            $stages['coverage'] = sprintf('❌ %d/%d', count($truth) - count($missing), count($truth));
            $notes[] = 'lost: "'.mb_substr($missing[0], 0, 60).'"'.(count($missing) > 1 ? ' (+'.(count($missing) - 1).' more)' : '');
        }

        // 3. RE-EMIT: XSD + python-docx round trip.
        $reemitted = $writer->write($doc);
        $tmp = tempnam(sys_get_temp_dir(), 'fidelity-');
        file_put_contents($tmp, $reemitted);

        if (is_file($xsd)) {
            $work = $tmp.'-x';
            mkdir($work);
            $zip = new \ZipArchive;
            $zip->open($tmp);
            $zip->extractTo($work);
            $zip->close();
            $xsdOk = true;
            foreach (glob($work.'/word/{document,styles,numbering,header*,footer*}.xml', GLOB_BRACE) ?: [] as $part) {
                exec(sprintf('%s --noout --schema %s %s 2>/dev/null',
                    escapeshellarg($xmllint), escapeshellarg($xsd), escapeshellarg($part)), $o, $c);
                if ($c !== 0) {
                    $xsdOk = false;
                    $notes[] = 'XSD: '.basename($part).' invalid';
                }
            }
            $stages['reemit-xsd'] = $xsdOk ? '✅' : '❌';
            exec('rm -rf '.escapeshellarg($work));
        } else {
            $stages['reemit-xsd'] = '⚪ no schemas';
        }

        $missingReemit = missingLines($truth, implode("\n", groundTruth($tmp)));
        if ($missingReemit === []) {
            $stages['reemit-read'] = '✅';
        } else {
            $stages['reemit-read'] = '❌';
            $notes[] = 're-emit lost: "'.mb_substr($missingReemit[0], 0, 50).'"';
        }

        // 4. STABILITY: one more read must be a fixed point.
        $html2 = fullHtml($serializer, $reader->read($reemitted));
        $stages['stable'] = normalizeText($html) === normalizeText($html2) ? '✅' : '❌';
        if ($stages['stable'] === '❌') {
            $notes[] = 'second read text differs';
        }
        unlink($tmp);
    } catch (\Throwable $e) {
        $stages['read'] = '❌ '.get_class($e);
        $notes[] = mb_substr($e->getMessage(), 0, 80);
    }

    if (str_contains(implode(' ', $stages), '❌')) {
        $fail = 1;
    }
    $rows[] = ['name' => $name, 'stages' => $stages, 'notes' => implode('; ', $notes)];
    printf("%-28s %s %s\n", $name, implode(' ', $stages), $rows[count($rows) - 1]['notes']);
}

// ---------------------------------------------------------------- report
$table = "| Document | Read | Text coverage | Re-emit XSD | Re-emit readable | Round-trip stable | Notes |\n|---|---|---|---|---|---|---|\n";
foreach ($rows as $r) {
    $table .= sprintf("| %s | %s | %s | %s | %s | %s | %s |\n",
        $r['name'], $r['stages']['read'], $r['stages']['coverage'],
        $r['stages']['reemit-xsd'], $r['stages']['reemit-read'],
        $r['stages']['stable'], $r['notes'] ?: '—');
}

$summaryDir = $root.'/build/conformance';
if (! is_dir($summaryDir)) {
    mkdir($summaryDir, 0777, true);
}
file_put_contents($summaryDir.'/summary-fidelity.md', "## Reader fidelity\n\n".$table);

file_put_contents($root.'/docs/READER-FIDELITY.md', <<<MD
# Reader fidelity

> Generated by `scripts/conformance/reader-fidelity.php` — do not edit
> the table by hand. Reproduce: run the corpus generators
> (`scripts/corpus/`), then this harness.

The reader's claim — parsing arbitrary externally-produced DOCX — is
verified against a corpus written by **independent producers**: PHPWord
(the companion library whose output we must read flawlessly),
python-docx, LibreOffice Writer, and php-docx's own writer as the
round-trip baseline. Manual-source slots (Word desktop, Google Docs
export, Word Online) live in `scripts/corpus/fixtures/` — see its README.

Per document, four non-circular checks:

1. **Read** — `DocxReader` parses without errors.
2. **Text coverage** — every text line that *python-docx* (an
   independent implementation) extracts from the original must appear in
   our AST's HTML serialization.
3. **Re-emit** — writing the parsed AST back to DOCX yields a file that
   passes the ECMA-376 Transitional XSD *and* still carries every
   ground-truth line when read by python-docx.
4. **Round-trip stability** — reading the re-emitted file serializes to
   the same text (fixed point after one cycle).

## Current corpus results

$table
Out-of-scope content (tracked changes, comments, footnotes, OMML,
charts) is intentionally dropped by the reader — the honest feature
boundary is listed in the [README](../README.md#out-of-scope).
MD);

echo "\nwrote docs/READER-FIDELITY.md\n";
exit($fail);
