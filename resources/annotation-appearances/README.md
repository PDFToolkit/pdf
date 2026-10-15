# PDF 2.0 annotation appearances

English | [Français](README.fr.md)

Core 0.9.1 corrects a missing conditional check: an ordinary `Square` annotation
with a nondegenerate `Rect` could previously be written as PDF 2.0 without `AP`.
ISO 32000-2 Table166, physical page483, requires that appearance. The retained
[source](../reference-documents/ISO_32000-2_sponsored_EC3.pdf) and
[coverage record](coverage.json) identify the exact clauses and byte fingerprint.

EC3 issue22 corrects the AP qualifier: required with its stated exceptions for
PDF2, optional for PDF1.2–1.7. Issue124 makes the rectangle indices zero-based.
Both exact approved annotation/reply chains are verified by
[`validate_ec3_semantic_reviews.py`](../../tools/validate_ec3_semantic_reviews.py).
They join the earlier four reviews; the 197 intrinsic dictionary rules remain
separate from these contextual graph checks.

## Required appearance and exceptions

The validator follows actual `Page.Annots` relationships through the native
`DocumentGraphContext`, including effective frozen revisions. PDF2 requires AP
unless **both** rectangle dimensions collapse to a point (`Rect[0]==Rect[2]`
and `Rect[1]==Rect[3]`), or the subtype is `Popup`, `Projection` or `Link`.
A horizontal or vertical line alone is not exempt. Reversing rectangle corners
does not change the test. Popup ownership and its prohibition on AP remain the
separate markup rules. Projection has an additional specific condition:
if either its width or its height is zero, AP is prohibited (§12.5.6.24, physical
page520). A nondegenerate Projection may supply AP.

Header/catalog version precedence applies. Upgrading a PDF1.7 catalog to PDF2
therefore checks annotations retained from earlier revisions. A writer must
explicitly provide their appearances in that update. PDF1.7 retains its previous
optional-AP behavior. Private dictionaries with similarly named keys are not
treated as page annotations.

## AP, states and Form streams

An AP dictionary requires `N`. `N`, optional `R` and optional `D` hold an actual
Form XObject stream or a dictionary of named Form streams. A state dictionary
in any of these slots requires a name `AS`. Optional null values keep the PDF
absence semantics. An unknown private AP entry does not become a new slot.

ISO2 physical page491 explicitly describes consumer fallback when AS has no
matching appearance. The graph check preserves that case without selecting an
arbitrary state or creating an appearance. The existing managed widget builder
retains its own explicit state-selection construction contract.

Every supplied appearance is a Form stream, with required numeric `BBox`,
optional numeric six-element `Matrix` and optional `FormType=1`. `Type` may be
absent; when present it is `XObject` (Table93, physical page287). A singular matrix
is not rejected by an invented invertibility requirement. Other Form resource
and PDF-version checks retain their existing graph validation.

Compose `FormAppearanceStream`, explicit content instructions and resources,
then put an `IndirectReference` to it in AP.N. Associate the annotation with the
actual page through `Annots`; construction does not silently draw a replacement
appearance. Appearance/state traversal has a cumulative construction budget.

## Evidence and limits

[`AnnotationAppearanceGraphTest`](../../tests/AnnotationAppearanceGraphTest.php)
checks the native graph, malformed AP/Form/state values, point versus line
exceptions, nulls, indirect entries, catalog upgrades and effective historical
stream replacement across all xref layouts. Existing annotation and geospatial
fixtures now supply explicit valid appearances without dropping their assertions.

```sh
python tools/validate_annotation_appearances.py --php php --qpdf qpdf --pdftoppm pdftoppm --work-dir /path/to/output
```

The oracle writes eight PDFs, checks eleven native rejections, reads the actual
AP/AS/Form objects with pypdf, checks qpdf syntax and compares 28 Poppler pixels.
The unmatched-state case is inspected structurally without claiming a particular
viewer fallback image. It does not test rollover interaction, invent missing
content, or establish universal PDF/WTPDF conformance.
