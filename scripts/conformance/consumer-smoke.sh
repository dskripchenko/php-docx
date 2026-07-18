#!/usr/bin/env bash
# Consumer smoke: the reference DOCX must survive two independent
# real-world consumers —
#   * LibreOffice headless converts it to PDF without errors;
#   * python-docx opens it and extracts the expected content markers.
#
# Usage: scripts/conformance/consumer-smoke.sh [docx-file]
#   default: build/conformance/reference.docx
#
# Env: SOFFICE — LibreOffice binary override (default: soffice).
#
# Writes summary-consumers.md next to the input; exits non-zero on any
# failure.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
DOCX="${1:-$ROOT/build/conformance/reference.docx}"
SOFFICE="${SOFFICE:-soffice}"

if [ ! -f "$DOCX" ]; then
    echo "Missing $DOCX — run scripts/conformance/generate.php first" >&2
    exit 2
fi

OUT="$(dirname "$DOCX")"
summary="$OUT/summary-consumers.md"
{
    echo "## Consumer smoke — LibreOffice + python-docx"
    echo
    echo "| Consumer | Check | Result |"
    echo "|---|---|---|"
} > "$summary"

fail=0

# --- LibreOffice: headless DOCX → PDF conversion.
rm -f "$OUT/reference.pdf"
if "$SOFFICE" --headless --convert-to pdf --outdir "$OUT" "$DOCX" >/dev/null 2>&1 \
   && [ -s "$OUT/reference.pdf" ]; then
    echo "PASS libreoffice convert-to pdf ($(wc -c < "$OUT/reference.pdf" | tr -d ' ') bytes)"
    echo "| LibreOffice ($("$SOFFICE" --version 2>/dev/null | head -1 | awk '{print $2}')) | headless DOCX→PDF | ✅ |" >> "$summary"
else
    echo "FAIL libreoffice conversion" >&2
    echo "| LibreOffice | headless DOCX→PDF | ❌ |" >> "$summary"
    fail=1
fi

# --- python-docx: open + extract content markers.
if python3 - "$DOCX" <<'PY'
import sys
import docx

d = docx.Document(sys.argv[1])
text = "\n".join(p.text for p in d.paragraphs)
assert "php-docx conformance reference" in text, "heading missing"
assert "Justified paragraph" in text, "body paragraph missing"
assert "кириллица" in text, "cyrillic text missing"
assert len(d.tables) == 1, f"expected 1 table, got {len(d.tables)}"
assert d.tables[0].rows[0].cells[0].text == "Feature", "table header cell mismatch"
print(f"PASS python-docx {docx.__version__ if hasattr(docx, '__version__') else ''}: "
      f"{len(d.paragraphs)} paragraphs, {len(d.tables)} table")
PY
then
    echo "| python-docx | open + content markers | ✅ |" >> "$summary"
else
    echo "FAIL python-docx extraction" >&2
    echo "| python-docx | open + content markers | ❌ |" >> "$summary"
    fail=1
fi

echo
cat "$summary"
exit "$fail"
