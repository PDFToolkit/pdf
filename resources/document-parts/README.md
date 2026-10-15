# Document parts and EC3 migration

English | [Français](README.fr.md)

The native object graph follows ISO 32000-2 EC3 corrections #86, #290, #609 and
#612 in the retained `ISO_32000-2_sponsored_EC3.pdf`, physical pages 850–853,
SHA-256 `90f4b666d46405dd62630a98d514158b4de5304b749523c4081680ac4fe2e230`.
The exact annotation identities and approval chains are in
[the semantic review register](../iso32000-2-2020/ec3-semantic-reviews.json).
#612 and #609 are PDF Association **Accepted** corrections; #86 and #290 are ISO
**Completed** corrections. The source PDF is preserved unchanged.

`DocumentPart::addChildren($first, ...$others)` replaces `addGroup()`. Child
references form one ordered, nonempty, **flat** `/DParts` array. To express a
hierarchical group, construct a parent `DocumentPart` and attach its children
explicitly. Repeated calls append siblings in order. The builder preserves one
parent per child, rejects cycles and repeated children, and leaves rejected
batches unattached. A page leaf cannot acquire children.

`Metadata` is forbidden on a `DocumentPart`, including effective incremental
replacements. Keep application workflow data in `DocumentPartMetadata` (`/DPM`).
To describe the hierarchy with XMP, pass one explicit `MetadataEntry` to the
`DocumentPartRoot` constructor after the root and optional record level, together
with the per-level `NameType` values. The variadic constructor parameter is now
named `entries`; calls that supplied `nodeNames:` by name must use `entries:`.
Ordinary positional name arguments keep their meaning. The executable
[`pdf20-metadata-declarations.php`](../../examples/pdf20-metadata-declarations.php)
shows the distinct catalog, DPartRoot, DPM and marked-content scopes.

DPM additionally accepts PDF `NameType` values, including nested or indirect ones,
whose decoded bytes are valid UTF-8. A PDF name's `#HH` serialization is handled by
`NameType`; supply the decoded name bytes. Dictionary keys still follow XML Name
rules. Nulls, streams and invalid UTF-8 name values remain refused.

The generated Table409 schema enforces the flat child-array shape and prohibits
`Metadata`. Its historical `MetadataEntry` definition remains in the base-source
inventory to preserve traceability; it does not authorize emitting that entry.
Native graph validation additionally checks reciprocal parents/pages, page-range
order, unique membership, depth/node bounds, record levels and per-level names.

`tools/validate_pdf20_metadata.py` independently checks generated PDFs with pypdf,
XML parsing and qpdf. Its three-page fixtures exercise the corrected hierarchy
under table, stream and hybrid cross references. PHPUnit also rejects incremental
replacements that reintroduce nested child arrays, DPart XMP or invalid DPM names.
