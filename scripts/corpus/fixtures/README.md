# Manual corpus sources

The generated corpus (PHPWord, python-docx, LibreOffice, own writer) is
built by the sibling scripts. Three sources cannot be produced headlessly
and are contributed manually into these directories:

- `word-desktop/` — documents saved by Microsoft Word (desktop);
- `google-docs/` — Google Docs → File → Download → .docx;
- `word-online/` — Word for the web → Save as.

Rules: only self-authored documents (no third-party/protected content);
name files by the feature they exercise (e.g. `word-desktop/tables-vmerge.docx`);
everything placed here is picked up automatically by
`scripts/conformance/reader-fidelity.php` alongside the generated set.
