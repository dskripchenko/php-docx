#!/usr/bin/env bash
# Validate every WordprocessingML part of the reference DOCX against the
# ECMA-376 Transitional XSD schemas (see scripts/fetch-ooxml-schemas.sh).
#
# Usage: scripts/conformance/xsd-check.sh [docx-file]
#   default: build/conformance/reference.docx
#
# Env: XMLLINT — binary override (default: xmllint).
#
# Writes summary-xsd.md next to the input; exits non-zero on any invalid
# part.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
DOCX="${1:-$ROOT/build/conformance/reference.docx}"
XSD_DIR="$ROOT/.cache/ooxml-xsd"
XMLLINT="${XMLLINT:-xmllint}"

if [ ! -f "$XSD_DIR/wml.xsd" ]; then
    echo "Schemas not cached — run scripts/fetch-ooxml-schemas.sh first" >&2
    exit 2
fi
if [ ! -f "$DOCX" ]; then
    echo "Missing $DOCX — run scripts/conformance/generate.php first" >&2
    exit 2
fi

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
unzip -oq "$DOCX" -d "$WORK"

summary="$(dirname "$DOCX")/summary-xsd.md"
{
    echo "## OOXML schema validation — ECMA-376 Transitional (xmllint)"
    echo
    echo "| Part | Result |"
    echo "|---|---|"
} > "$summary"

fail=0
found=0
for part in "$WORK"/word/document.xml "$WORK"/word/styles.xml \
            "$WORK"/word/numbering.xml "$WORK"/word/header*.xml \
            "$WORK"/word/footer*.xml; do
    [ -f "$part" ] || continue
    found=$((found + 1))
    name="word/$(basename "$part")"
    if "$XMLLINT" --noout --schema "$XSD_DIR/wml.xsd" "$part" 2> "$WORK/err.txt"; then
        echo "PASS $name"
        echo "| $name | ✅ |" >> "$summary"
    else
        echo "FAIL $name" >&2
        sed 's/^/  /' "$WORK/err.txt" | head -5 >&2
        firstErr=$(head -1 "$WORK/err.txt" | sed 's/|/\\|/g' | cut -c1-160)
        echo "| $name | ❌ $firstErr |" >> "$summary"
        fail=1
    fi
done

if [ "$found" -eq 0 ]; then
    echo "No WordprocessingML parts found in $DOCX" >&2
    exit 2
fi

echo
cat "$summary"
exit "$fail"
