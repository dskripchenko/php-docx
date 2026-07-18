#!/usr/bin/env bash
# Corpus generator: DOCX produced by LibreOffice Writer (headless HTML →
# DOCX conversion) — a third independent producer.
#
# Usage: scripts/corpus/generate-libreoffice.sh [out-dir]
#   default: build/corpus
# Env: SOFFICE — binary override (default: soffice).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
OUT="${1:-$ROOT/build/corpus}"
SOFFICE="${SOFFICE:-soffice}"
mkdir -p "$OUT"

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

cat > "$WORK/libreoffice-features.html" <<'HTML'
<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>LibreOffice source</title></head>
<body>
<h1>LibreOffice-produced document</h1>
<p>Paragraph with <b>bold</b>, <i>italic</i> and кириллица.</p>
<p style="text-align: justify">Justified paragraph converted from HTML by
LibreOffice Writer, giving the reader a third independent producer.</p>
<h2>Table</h2>
<table border="1">
  <tr><th>One</th><th>Two</th></tr>
  <tr><td>A</td><td rowspan="2">tall cell</td></tr>
  <tr><td>B</td></tr>
</table>
<h2>List</h2>
<ul><li>first</li><li>second<ul><li>nested</li></ul></li></ul>
</body></html>
HTML

"$SOFFICE" --headless --infilter="HTML (StarWriter)" --convert-to "docx:MS Word 2007 XML" --outdir "$OUT" "$WORK/libreoffice-features.html" >/dev/null 2>&1
[ -s "$OUT/libreoffice-features.docx" ] || { echo "LibreOffice produced no output" >&2; exit 1; }
echo "generated libreoffice-features.docx in $OUT"
