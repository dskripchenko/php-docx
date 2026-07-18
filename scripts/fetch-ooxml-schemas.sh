#!/usr/bin/env bash
# Download the ECMA-376 Transitional XSD schemas used to validate the
# writer's OOXML output (scripts/conformance/xsd-check.sh).
#
# Source: ECMA-376 Part 4 (Transitional Migration Features), 5th edition —
# freely downloadable from ecma-international.org. php-docx emits the
# transitional namespaces (the same ones Word writes by default), so the
# transitional schema set is the correct one to validate against.
#
# The schemas import the W3C xml namespace schema without a
# schemaLocation; a local copy of xml.xsd is fetched and the imports are
# patched to reference it, so validation works fully offline.
set -euo pipefail

CACHE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/.cache/ooxml-xsd"
mkdir -p "$CACHE_DIR"
cd "$CACHE_DIR"

if [ -f wml.xsd ] && [ -f xml.xsd ]; then
    echo "OOXML transitional schemas already cached at $CACHE_DIR"
    exit 0
fi

ECMA_URL="https://ecma-international.org/wp-content/uploads/ECMA-376-4_5th_edition_december_2016.zip"
ECMA_SHA="bd25da1109f73762356596918bf5ff8b74a1331642dba5f1c1d1dfc6bed34ecd"
XML_XSD_URL="https://www.w3.org/2001/xml.xsd"
XML_XSD_SHA="61960fb3131e38022caad5360e2f33a3382578ab3c80cd58bd74320ede61b20c"

echo "Downloading ECMA-376 Part 4 (Transitional)..."
curl -sSL "$ECMA_URL" -o ecma376-4.zip
ACTUAL=$(shasum -a 256 ecma376-4.zip | awk '{print $1}')
if [ "$ACTUAL" != "$ECMA_SHA" ]; then
    echo "ERROR: SHA256 mismatch for ECMA-376-4 zip (expected $ECMA_SHA, got $ACTUAL)"
    rm -f ecma376-4.zip
    exit 1
fi
unzip -oq ecma376-4.zip 'OfficeOpenXML-XMLSchema-Transitional.zip'
unzip -oq OfficeOpenXML-XMLSchema-Transitional.zip
rm -f ecma376-4.zip OfficeOpenXML-XMLSchema-Transitional.zip

echo "Downloading W3C xml.xsd..."
curl -sSL "$XML_XSD_URL" -o xml.xsd
ACTUAL=$(shasum -a 256 xml.xsd | awk '{print $1}')
if [ "$ACTUAL" != "$XML_XSD_SHA" ]; then
    echo "ERROR: SHA256 mismatch for xml.xsd (expected $XML_XSD_SHA, got $ACTUAL)"
    rm -f xml.xsd
    exit 1
fi

# Point the schemaLocation-less xml-namespace imports at the local copy
# (GNU and BSD sed compatible).
for f in *.xsd; do
    sed -i.bak 's|<xsd:import namespace="http://www.w3.org/XML/1998/namespace"/>|<xsd:import namespace="http://www.w3.org/XML/1998/namespace" schemaLocation="xml.xsd"/>|' "$f"
    rm -f "$f.bak"
done

echo "Done. Schemas at $CACHE_DIR"
