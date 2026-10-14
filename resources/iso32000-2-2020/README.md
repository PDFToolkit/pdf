# ISO 32000-2:2020 EC3 pilot catalogue

English | [Français](README.fr.md)

This directory records the independently identified PDF 2.0 edition and the
reviewed schemas introduced in this increment. It is **not the complete
ISO 32000-2 catalogue and does not establish full PDF 2.0 conformance**.

The supplied source is `ISO_32000-2_sponsored_EC3.pdf`, 1,023 physical PDF pages,
SHA-256 `90f4b666d46405dd62630a98d514158b4de5304b749523c4081680ac4fe2e230`.
Its original bytes and filename are preserved at the
[repository root](../../ISO_32000-2_sponsored_EC3.pdf). This copy comes from the
[PDF Association sponsored access](https://www.pdfa.org/sponsored-standards/)
and is published with the contributor’s confirmed redistribution permission.
It is excluded from Composer archives.
The standard edition is ISO 32000-2:2020; EC3 is a separately selected correction
collection dated 2026-06-01. The PDF container holding the standard is PDF 1.7,
which does not change the PDF 2.0 version specified by its contents.

| Table | Physical PDF pages | Reviewed entry schemas |
| --- | --- | --- |
| 29, document catalog | 113–117 | 32 |
| 43, file specification | 149–152 | 16 |
| 356, namespace | 744 | 4 |
| 409a, associated-file marked-content property list | 1015 | 1 |

`dictionaries.json` contains concise implementation descriptions written for
this project. It preserves the physical source locations and selected reviewed
constraints, with structured correction provenance. It does not reproduce the
standard's paragraphs. `coverage.json` explicitly lists remaining work.

The source explains the review states on physical page 2: `Completed` means
approval by ISO TC 171 SC 2 WG 8; `Accepted` means approval by the PDF Association
PDF Technical Working Group. These authorities remain distinct in the immutable
`ErrataReviewStatus` and `ErrataResolution` objects. Annotation/reply relations
were checked for the pilot's direct-object and indirect-reference corrections.
Table 409a is the replacement property-list model from
[erratum 374](https://github.com/pdf-association/pdf-issues/issues/374): MCAF is
an array inside a dictionary, and marked content uses a named property resource.

The concrete classes live in `PDF\Specification\Iso32000_2`. A `SourceEdition`
identifies the logical standard independently of the supplied copy's hash and
the output file version. ISO 32000-1 and the Adobe PDF 1.7 supplement remain
separate from this corpus. `Catalogue` rejects definitions from mixed families,
even when their table identifiers differ.

Reproduce or compare the 61 generated PHP files and their manifests:

```console
python tools/generate_iso32000_2_pilot.py
python tools/generate_iso32000_2_pilot.py --check
```

The generator consumes the authored, reviewed pilot JSON; it does not extract
the source PDF. A future complete extractor must recognise letter-suffixed
errata tables, continued grids without repeated captions, annotation review
replies and appended replacement pages. Plain page text alone is insufficient.

The object schemas enforce declared PDF value families, fixed Type names,
unconditional required entries and selected directness constraints. Native
graph mechanisms are responsible for context-dependent associated-file rules.
The mere presence of entries for namespaces, DSS or DPartRoot does not implement
those mechanisms. Tests: `tests/Iso32000_2SpecificationTest.php`.

Native additions are listed separately in `coverage.json`, with their actual
implementation, unit-test and independent-validator files: PDF20 header/catalog
and frozen incremental-version checks; typed UTF-8/UTF-16BE/PDFDoc strings;
AF dictionary associations; named MCAF content with frozen emission facts;
PDF20 Form XObjects' own resources; bounded source date validation; R5 prohibition
and explicit document-CMS permission constraints. The example associates the
same CSV with catalog, page and named marked content, while keeping visible
CID text separate from UTF-8 metadata. `BeginAssociatedFilesMarkedContent`
composes the existing BDC token and adds no operator to Annex A.

These stated domains do not cover arbitrary opaque content interpretation,
R6 encryption, native namespace/role graphs, DSS/timestamps, DPart hierarchies,
encrypted wrappers or complete PDF 2.0 conformance. Date/MIME checks preserve
supplied information; the core does not invent or fetch source file metadata.
