#!/usr/bin/env python3
"""Ground-truth text extractor for the reader-fidelity harness.

Prints every non-empty paragraph and table-cell text of a DOCX (body,
headers, footers), one per line, whitespace-normalized — extracted by
python-docx, i.e. by an implementation independent of php-docx.

Usage: python3 extract-text.py <file.docx>
"""

import re
import sys

import docx


def norm(s: str) -> str:
    return re.sub(r"\s+", " ", s).strip()


def emit(text: str) -> None:
    n = norm(text)
    if n:
        print(n)


d = docx.Document(sys.argv[1])

for p in d.paragraphs:
    emit(p.text)
for t in d.tables:
    for row in t.rows:
        for cell in row.cells:
            emit(cell.text)
for section in d.sections:
    for container in (section.header, section.footer):
        for p in container.paragraphs:
            emit(p.text)
