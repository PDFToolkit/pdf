# ISO 32000-2:2020 dictionary catalogue and selected EC3 corrections

English | [Français](README.fr.md)

This edition-specific catalogue inventories all **440 source tables**, including
appendices and numbering placeholders. Its **306 dictionary definitions** provide
**1,702 fixed entry classes and seven explicit variable-key classes**. The generator
produces **2,322 PHP files**, including an immutable
`PDF\Specification\Iso32000_2\Catalogue` aggregation. These counts describe the
dictionary inventory. Conditional, algorithmic and document conformance remain
separate, explicitly tracked work in [coverage.json](coverage.json).

The canonical source is [the retained original](../../ISO_32000-2_sponsored_EC3.pdf),
1,023 physical PDF pages, SHA-256
`90f4b666d46405dd62630a98d514158b4de5304b749523c4081680ac4fe2e230`.
It comes from the [PDF Association sponsored standards](https://www.pdfa.org/sponsored-standards/)
and is published with the contributor’s confirmed redistribution permission.
The [complete supplied bundle](../iso32000-2-bundle/) also preserves the separately
supplied copy. Source PDFs and extraction evidence are excluded from Composer
archives. The logical edition is ISO 32000-2:2020, with EC3 selected separately
(2026-06-01). The source PDF’s PDF 1.7 container does not change the specified
PDF 2.0 version.

The review preserves table identities, physical pages, actual preceding clause
headings, continued grids without repeated captions, case-sensitive names such as
`/OP` and `/op`, variable keys, value families, directness, conditional and inheritable
requirements, and introduction/deprecation wording. Common stream, function,
annotation, action, field and attribute tables come from this same edition.
No ISO 32000-1 definition is silently substituted. Table 117 is explicitly empty
in the standard and creates no dictionary class.

The retained inputs are:

- `source-extraction.json`: raw table grids, dictionary rows and anomalies tied
  to the canonical source fingerprint;
- `extraction-review.json`: reviewed broken grids, capitalization hints, exact
  clauses, common table relationships, singleton names and closed name alternatives;
- `reviewed-overrides.json`: the 53 existing authored summaries and selected EC3
  constraints, retaining their public class names;
- `dictionaries.json`, `tables.json`, `classes.json`, `generated-files.json`:
  reproducible compiled inventories and concrete class mapping.

New normative descriptions identify `description_origin: normative-source` and
`description_language: en`. The 53 project summaries remain `project-authored`.
English source prose is identified separately from translated documentation.
Whitespace and obvious extraction punctuation are normalized; the retained PDF
remains the reference. Source prose is kept intact when an explicit errata overlay
changes a constraint.

Name restrictions are reviewed explicitly. The compiler does not turn the first
“shall be X” into a singleton: that phrase may concern another key, a conditional
branch or several alternatives. `NameAlternatives` represents closed sets such as
`Sig`/`DocTimeStamp`, `Square`/`Circle` and `CIDFontType0`/`CIDFontType2`. Contextual
cases such as `BaseEncoding` keep their wording without an invented singleton.
Array-only blend-mode deprecation does not deprecate every value of `/BM`.

Correction authorities stay distinct: `Completed` means ISO TC 171 SC 2 WG 8
approval; `Accepted` means PDF Association PDF Technical Working Group approval.
`ErrataResolution` retains physical page, issue and annotation identity. Examples
include completed ToUnicode table 125a (issue 462) and associated-file property
list 409a (issue 374). Remaining EC3 annotations have **not** all received semantic
implementation review.

Regenerate from retained evidence and compare PHP objects without PDF extraction
dependencies:

```console
python tools/compile_iso32000_2_catalogue.py --check
python tools/generate_iso32000_2.py --check
```

To update extraction deliberately, install development-only pypdf and pdfplumber,
run `tools/extract_iso32000_2.py --output <directory>`, review all source changes
and anomalies, then compile with
`tools/compile_iso32000_2_catalogue.py --extraction <directory>`. Raw extraction
never automatically becomes reviewed conformance data.

`tests/Iso32000_2SpecificationTest.php` exercises every constructor, repaired grids,
name alternatives, negative constraints, OPI keys and edition isolation. Native
namespaces, TS 32005 inclusion, associated files, document parts, encrypted wrappers,
metadata, password encryption, signatures, DSS and timestamps have separate test
and external-validator evidence in `coverage.json` and bundle coverage.
A schema object does not certify rendering, accessibility, PKI trust, content
semantics or complete PDF 2.0 conformance.
