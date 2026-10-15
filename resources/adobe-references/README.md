# Historical Adobe references and the 2007 errata review

English | [Français](README.fr.md)

All supplied PDFs retain their original names and bytes in
`resources/reference-documents/`. `metadata.json` records each source and the
portfolio's embedded documents. The original reference, its errata, the redaction
addendum and the later Adobe extension supplement are distinct authorities.

`errata-review.json` classifies **65 correction records** from all content pages
of the 18-page `pdf_17_errata.pdf`. It links implemented constraints to actual PHP
objects and tests, separates editorial references and historical viewer notes,
and explicitly identifies consumer operations and unfinished semantic work.
Reviewing every correction does not mean that every processor behavior is implemented.

This review adds executable callout coordinate/name constraints, prohibits empty
page content arrays and implements corrected annotation appearance placement.
Outline counts, duplicate keys, encryption exemptions, key/filter conventions,
field names, collections and signature seeds already have native implementations.
The 2007 removal of a base-reference digest seed flag does not erase the later
Adobe extension that explicitly adds it again.

Full authenticated DocMDP/FieldMDP/UR revision authorization, legacy Acrobat
emulation, layout truth and arbitrary font-program validation are not implied.
The destructive redaction processor has its own [source coverage](../redaction/).
