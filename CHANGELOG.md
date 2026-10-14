# Changelog

English | [Français](CHANGELOG.fr.md)

## [Unreleased]

## [0.7.1] - 2026-10-14

- Group all eighteen original specification PDFs under
  `resources/reference-documents/`, with a bilingual index and their original
  filenames and bytes. Keep the test fixtures beside their tests.
- Record repository paths separately from document identities; update source
  catalogues, generators, evidence links and validation tools.
- Exclude the reference collection from Composer archives and retain the
  documentation site's public PDF download addresses.
- Lock the reader development dependency to its published 0.1.9 documentation
  correction.

## [0.7.0] - 2026-10-14

### PDF 2.0 objects and companion mechanisms

- Preserve all eleven supplied bundle originals unchanged, with PDF Association
  provenance, physical pages, hashes and annotation counts. Record implementation,
  independent evidence and limits separately for each document; exclude originals
  from Composer distribution archives.
- Expand the edition-specific dictionary catalogue beyond the initial four tables:
  include continuation pages, fixed and variable keys, common definitions and
  explicit reviewed corrections. Preserve case-sensitive PDF names and existing
  authored summaries. Complete table coverage does not imply complete conformance.
- Implement SHA-3 and fixed-output SHAKE256, the named ECDSA/Brainpool and EdDSA
  algorithms with their permitted digest pairs, and explicit ISO extension arrays.
- Add R=6 credentials, R=7 AES-GCM, full-width random IV allocation with bounded
  key usage, authenticated decryption and the NIST plaintext bound.
- Implement standalone and signature-attached PDF MAC over actual serialized
  revisions, including encrypted initial, incremental and linearized output.
  Keep historical bytes, exact DER lengths and single-revision hybrid signatures.
- Add RFC3161 document timestamps, explicit authority providers, token/request and
  certificate binding, DSS/VRI objects and signed-revision ordering. Preserve
  FieldMDP and field locks while proving the narrow DocMDP maintenance exception
  through a complete frozen-delta comparison.
- Add XMP/RDF construction, declarations and claim data, object/marked-content
  metadata, black point compensation, document parts and encrypted-payload wrappers.
  Validate effective resource ownership and freeze native emission facts.
- Add qualified standard/custom namespaces, transitive role maps and the full
  ISO/TS 32005 inclusion matrix with explicitly selected corrections.
- Model geospatial coordinate systems, paired control points, measurements,
  viewports, display units, affine matrices and point clouds; validate actual
  page, Form, image and 3D contexts across frozen revisions.
- Add bounded WTPDF mechanical audits that distinguish failures, human review and
  additional validation. These reports do not certify WTPDF or PDF/UA-2.
- Trace 199 WTPDF aspects to source clauses, explicit execution boundaries and
  evidence files; reject omitted normative leaf clauses and missing evidence.
- Add local, named, remote and embedded structure destinations, ordered content
  traversal, explicit page fallbacks and frozen navigation relationships.
- Require explicit PDF2 simple-font metrics and indirect descriptors, including
  standard fonts; retain the earlier omission only for PDF1.0–1.7. Validate raw
  resources and frozen revisions, and supply explicit Courier metrics in examples.

### Runtime, examples and validation

- Require 64-bit PHP 8.3 or later; validate PHP 8.3, 8.4 and 8.5. Keep optional
  PDF modules out of production dependencies and lock development modules to 0.1.8.
- Add explained examples for metadata/declarations, encrypted payloads, namespaces,
  modern signatures and document timestamps/DSS, with bilingual central tutorials.
- Verify actual ciphertext, MACs, CMS signatures, timestamps, historical revisions,
  metadata, structure and rendered examples with independent implementations.
  Certificate trust and complete semantic/profile conformance remain explicit.

## [0.6.2] - 2026-10-14

### Preserved PDF 2.0 reference

- Archive the supplied ISO_32000-2_sponsored_EC3.pdf unchanged, with its original
  filename, 1,023 pages, byte count and SHA-256; record PDF Association sponsored
  access as its provenance and the contributor's confirmed redistribution permission.
- Update bilingual source references and verify the archived file identity.
  Exclude the reference PDF from Composer archives; PHP APIs and dependencies are unchanged.

## [0.6.1] - 2026-10-14

### EC3 source traceability

- Attach the PDF 2.0 Form XObject resource requirement explicitly to completed
  erratum 292, physical page 287, clause 8.10.2 and table 93.
- Attach encrypted-catalog placement to completed erratum 439, physical pages
  77 and 112, clauses 7.5.7 and 7.7.2. Both rules were implemented and tested
  in 0.6.0; this correction completes their coverage references.
- Preserve the PHP API, emitted PDF behavior and locked dependency versions.

## [0.6.0] - 2026-10-14

### Initial PDF 2.0 mechanisms

- Add explicit PDF 2.0 file-header and effective catalog version declarations.
  Keep the existing PDF 1.7 construction as the default.
- Compose `TextString` with explicit PDFDoc/UTF-16BE and PDF 2.0 UTF-8 encoding
  strategies. Preserve font character codes as a distinct content mechanism.
- Add associated-file relationship objects, AF arrays, owner validation and the
  corrected MCAF named-property construction from EC3. Check actual emitted
  graphs and incremental revisions; keep supplied file bytes explicit.
- Introduce immutable edition and errata provenance, distinguishing Completed
  ISO approval from Accepted PDF Association TWG approval. Keep the private
  supplied copy's identity separate from the logical standard and output version.
- Add the separate ISO 32000-2:2020 EC3 pilot: four dictionary schemas, 53
  named entry classes, physical source pages and a reproducible generator.
  Reject mixed catalogue families/profiles and scope the historical OPI rule
  to its actual ISO 32000-1 source.
- Distinguish introduced, deprecated and prohibited features. Reject revision 5
  encryption in PDF 2.0 and apply its public-key permission requirements.
- Add a PDF 2.0 example with Unicode metadata, an embedded CSV associated with
  the catalog and page, named marked-content properties and an embedded font.

### Scope and distribution

- Keep PHP 8.3 minimum and validation under PHP 8.3, 8.4 and 8.5.
  Optional modules 0.1.7 also accept the core 0.6 series.
- Preserve ISO 32000-1 and Adobe supplement catalogues and original retained PDFs.
  Record ISO 32000-2 source provenance without publishing its personalised copy.
- Keep revision 6 encryption, the complete PDF 2.0 dictionary catalogue, native
  namespaces, DSS/timestamps, document parts and complete conformance pending.
  Publish paired English/French guidance and a real PDF tutorial.

## [0.5.5] - 2026-10-14

### Bilingual documentation

- Makes English the default README and documentation entrypoint, with complete
  French counterparts for the README, security policy and release history.
- Pairs retained guide and tutorial pointers with their French versions and
  links to the matching language on the central documentation site.
- Preserves the PHP API, PHP 8.3 minimum, executable examples, specification
  resources and original reference PDF bytes.
- Documents all executable example comments in English while preserving every
  executed token and demonstration value; corrects two source-comment encodings.
- Locks optional development modules to their published 0.1.6 releases.

## [0.5.4] - 2026-10-14

### Distribution

- Publish `roukmoute/pdf` and the three optional PDFToolkit modules on Packagist.
  Applications install stable releases with `composer require`; custom VCS
  repository declarations are no longer needed.
- Remove custom VCS repositories from the development manifest and lock the
  published optional module 0.1.5 releases. Preserve the selected tool versions
  and keep the core independent of optional modules in production.
- Link Composer metadata and installation guides to the shared documentation
  and package pages. Verify stable consumers, indexed tag commits and package
  synchronization as part of releases.

The PHP API, PHP 8.3 minimum, original reference PDFs and runtime source are
unchanged.

## [0.5.3] - 2026-10-14

### Documentation

- Move shared guides, tutorials, site assets, API export and site generation to
  [PDFToolkit/pdftoolkit.github.io](https://github.com/PDFToolkit/pdftoolkit.github.io).
  The common documentation lives at https://pdftoolkit.github.io/ with dedicated
  core, reader, image and font entry points. PHP sources, executable examples,
  fixtures, original Adobe PDFs and specification catalogues remain in the core.
- Update repository and security links to the shared site. Document its separate
  ownership and release process, using published package versions and exact
  source commits for API and example provenance.
- Preserve the existing `/pdf/` project site through validated HTML redirects
  carrying queries and fragments, with unchanged copies of the actual binary
  downloads. The compatibility workflow uses an immutable documentation-tool
  commit and verifies its complete output before deployment.

### Development dependencies

- Lock optional modules to their published 0.1.4 documentation releases. The
  core remains independent of them in production. PHP 8.3 and the PHP API are
  unchanged.

## [0.5.2] - 2026-10-14

### Fixed

- Omit the outline root `Count` when all top-level items are explicitly closed,
  including expanded descendants hidden beneath closed parents. Preserve the
  existing visible total for an explicitly expanded top-level item, item-level
  signed counts and sibling links (ISO Table 152, physical PDF page 376;
  Adobe PDF 1.7 errata page 10).

### Documentation and reference integrity

- Retain the four supplied Adobe PDF 1.7 originals unchanged: the November 2006
  reference, October 2007 errata, redaction addendum and Acrobat SDK 8.1 portfolio.
  Record their fingerprints and the portfolio's four embedded sources, including
  its three exact duplicates of the separately supplied documents.
- Explain their usefulness, edition-specific differences and the distinction
  between a `Redact` dictionary and destructive content removal. Archival intake
  does not replace active ISO/Adobe definitions or claim a complete errata audit.
- Add a documentation guide and validated downloads. Check source bytes, physical
  pages and attachments independently in both CI workflows. Exclude the four new
  reference PDFs from Composer distribution archives while preserving them in Git.
- Keep PHP 8.3 as the minimum version and optional modules at their published
  0.1.3 releases. No new production dependency or PDF 2.0 support is introduced.

## [0.5.1] - 2026-10-14

### Fixed

- Mark the editable text and choice appearances in the AcroForm example with
  `/Tx BMC ... EMC`, so a viewer replaces the existing variable text instead of
  appending another value. Keep borders outside the replaceable section and
  scope clipping and graphics state inside it (ISO 12.7.3.3, physical page 444;
  Adobe supplement page 121, note 119).
- Draw the choice list as its two actual display labels and selected rows,
  retaining `FR`/`BE` as exported data. Apply the same variable-text structure
  to the reference field in the signature-policy example.
- Explain editing and appearance replacement in the AcroForm tutorial. Add
  independent parsed-stream regression checks and repeated replacement evidence;
  absence of the marker remains a permitted fallback for generic appearances.

## [0.5.0] - 2026-10-13

Immutable revision histories, native object generations, navigation, attachments,
portable collections and applied signature-field policies. PHP 8.3 remains the
minimum version, with validation on PHP 8.3, 8.4 and 8.5.

### Added

- Return exact initial snapshots from `LinearizedWriter::revision()` and append ordinary incremental updates while preserving every original byte and hint extent.
- Model immutable free lists, release and reuse operations, retired generations and effective ordinary/compressed xref indexes. Preserve obsolete aliases as their original number/generation; distinguish never-used holes and permanent generation 65535.
- Construct native page labels, named and explicit destinations, viewport calculations, article threads and beads, transitions and presentation sequences. Validate their actual effective catalog and page relationships after updates.
- Construct file specifications, embedded-file bytes and derived metadata, Mac resource information, related-file arrays, attachment name trees and portable collection schemas/items/sorts. Declare external-stream data and filters without loading external files.
- Apply written signature seeds, locks, certificate facts, actual appearances and evidence contracts before finalization. Verify the actual CMS/PKCS#1 output on the exact reserved bytes and freeze the selected credential and digest.
- Retain historical certification and field-lock restrictions when signing another field, including renamed descendants and retired signature values. Enforce first/unique certification and usage-signature cardinality without making the optional Perms/DocMDP link mandatory.
- Add four explained tutorials with real PDFs for navigation, attachments, generations and signature-field policies. Add permanent independent oracles for the new mechanisms.

### Fixed

- Capture stream dictionaries and encoded pre-encryption bytes from the actual same-call emission, including managed entries and remapped reference identities. Keep emission evidence scoped to number and generation.
- Retain temporary objects during graph discovery so generated dictionary entries cannot be skipped when PHP reuses an object ID. Keep direct values distinct from their optional historical indirect identities.
- Validate effective page resources, boxes, rotation and name-tree byte ordering/limits. Check file-name, EF/RF/CI and collection-schema relationships against frozen and replaced objects.
- Resolve external-stream declarations against actual generations before applying a file-data exemption. Preserve explicit internal replacements and reject loss of an unchanged historical external dependency.
- Preserve the original file-specification F version and the correct continued PageLabels source clause; enforce the printed indirect-reference constraint for CI.
- Verify native OpenSSL signature results and reject mismatched credentials, digest declarations, malformed DER or replayed verification facts.

### Development dependencies

- Lock optional modules to their published 0.1.3 releases, accepting core `^0.2 || ^0.3 || ^0.4 || ^0.5`. The core still has no module dependency in production.

### Scope

- Dictionary definitions remain complete for both retained references. These additions do not claim complete semantic conformance or implementation of every native source procedure.
- General authenticated revision-difference analysis for DocMDP/FieldMDP/UR, remaining annotation/measurement/media/function rules and barcode appearance procedures remain tracked in [the coverage inventory](docs/coverage.md).
- External parsing/conversion, rendering, XFA/JavaScript/media execution, timestamp/revocation acquisition and certificate trust remain explicit external components.
- See [the 0.5 guide](docs/migration-0.5.md) for new APIs and the distinction between writable objects and emitted snapshots.

## [0.4.0] - 2026-10-13

Native writing mechanisms with explicit object construction, independent interoperability checks and a coverage inventory that separates definitions from algorithms and semantic rules. PHP 8.3 remains the minimum version.

### Added

- Construct AcroForm fields, widgets, appearances and FDF; tagged structures, MCID/MCR/OBJR relationships, ParentTree and IDTree.
- Construct calibrated and special colour spaces, patterns and all seven shading types, including native binary meshes; sampled images, masks, transparency, blending, halftones and optional-content configurations.
- Model native font families, explicit font programs, horizontal and vertical CID metrics, encoding vectors and CMaps. Scope subset-name uniqueness to each document.
- Encode LZW with both EarlyChange values and TIFF/PNG predictors across the permitted sample depths. Validate transitions, resets and decoded bytes independently.
- Write ISO R=2/3/4 password encryption and explicit CMS recipient encryption with per-filter keys. Preserve encrypted incremental revisions, metadata exemptions and recipient permissions.
- Sign fixed byte ranges using the three ISO signature formats. Model intrinsic seed, certificate, DocMDP, FieldMDP and usage-rights policies; reject required policies whose orchestration remains unsupported.
- Write hybrid cross references and native Annex F linearization, including shared resources, thumbnails, embedded files, object streams and encrypted variants.
- Add nine explained tutorials with generated PDFs, searchable component documentation and reproducible validations using qpdf, pypdf, pyHanko, ASN.1 and cryptographic checks.
- Add source-backed coverage inventories for both retained PDFs; distinguish complete dictionary definitions from remaining native semantics and external processors.

### Changed

- Replace the five remaining production static factories with explicit value objects and constructors. See [the migration guide](docs/migration-0.4.md).
- Encode text strings in PDFDocEncoding when representable, otherwise UTF-16BE with a BOM; expose explicit subset-font names and normalized sample layouts.
- Lock development documentation modules to their published 0.1.2 releases, which accept core `^0.2 || ^0.3 || ^0.4`; production dependencies remain limited to PHP extensions.

### Fixed

- Preserve source constraints for attribute orientation, padding, shading functions and continued tables without discarding printed provenance.
- Freeze direct revision containers, resolve effective bindings and reject structural references to pages outside the effective page tree.
- Prevent object-stream replacements of Encrypt and stale metadata exemptions; apply explicit Crypt keys directly as required by ISO 7.4.10.
- Require widget appearance state for every stateful appearance branch. Enforce native signature algorithm and key-size version requirements before signing.

### Scope

- This release does not claim complete implementation of both source documents. Free-object reuse, revisions after linearization, signature-policy orchestration and further graph, annotation and resource semantics remain tracked in [the coverage inventory](docs/coverage.md).
- External PDF parsing, image/font conversion, rendering, XFA, JavaScript execution, media codecs, geodetic transforms and certificate trust remain separate responsibilities.

## [0.3.0] - 2026-10-13

Adobe PDF 1.7 extension level 3, preserving the supplied specification and composing its definitions with the ISO object model. PHP 8.3 remains the minimum version.

### Added

- Retain `adobe_supplement_iso32000.pdf` byte for byte, with its SHA-256, physical page references and a standing repository rule to preserve future Adobe PDFs supplied to the project. Both specification PDFs are downloadable from the documentation site and their hashes are checked during its build.
- Model all 61 printed dictionary tables in the supplement through 404 generated dictionary, immutable definition and entry components. ISO and Adobe entries keep their respective document provenance. Inventory all 66 tables, algorithms, prose prescriptions, product implementation notes, external references and source anomalies separately.
- Add explicit developer-extension objects, Bates artifacts and resolved rich-media window dimensions. A documented tutorial produces a PDF declaring Adobe level 3 and displaying Bates pagination.
- Implement 3D unit mapping with an explicit interpretation of the source's conflicting equation and unit description. Add barcode module-width calculation and DataPrep byte preparation; symbology codecs remain external standards.
- Implement the explicitly selected historical R=5 Standard Security Handler, Unicode 3.2 SASLprep, credentials, permissions and AES-256 string/stream serialization. Its optional OpenSSL backend is not a production dependency for ordinary PDF generation. Validate interoperability with qpdf and pypdf, official AES vectors and an independent Unicode oracle.
- Add separately scoped R=2–R=4 password key derivation and the public-key SHA-256 recipient digest, validated with independent vectors. These primitives do not add RC4/AES-128 writers, CMS parsing or certificate encryption.

### Fixed

- Enforce direct extension declarations throughout the object graph, as required by ISO 7.12.1, and correctly validate array-first unions and indirect array members. Record source inconsistencies as explicit, page-backed implementation decisions.
- Reject direct trailer strings outside `/ID` during encrypted serialization. Respect only the catalog metadata exemption; keep page and image metadata encrypted. Prevent double encryption of content and object streams, invalid signature exemptions and unsupported encrypted revision snapshots.
- Reproduce Unicode tables across Python versions using the retained RFC 3454 character ranges and an explicit exclusion of algorithmic Hangul decompositions, which the PHP normalizer already handles separately.
- Pin the independently used qpdf validator to the checksum-verified official 12.4.2 binaries in CI. Older qpdf versions misinterpret encrypted non-catalog metadata; PDF fixtures and the writer retain the specification's correct metadata boundaries.

### Compatibility and scope

- `CatalogDictionary\Extensions` now accepts a direct dictionary only. Replace indirect extension declarations with direct dictionaries; nested references and streams in declarations are also rejected. All extension declarations must contain at least one developer prefix before serialization.
- Optional modules 0.1.1 accept core `^0.2 || ^0.3`; development dependencies and documentation sources are locked to their published tags. The core still has no runtime dependency on them.
- R=5 is a historical interoperability feature, not a modern security default; its password derivation is old and AES-CBC does not authenticate contents. Rendering 3D/rich media, XFA processing, geodetic transforms, signing, barcode symbology codecs and encrypted incremental revisions are outside this release's implemented behavior. See [the supplement guide](docs/adobe-supplement.md) for the exact scope.

## [0.2.1] - 2026-10-13

Integration fixes for development checkouts and documentation builds. The production object model and API are unchanged from 0.2.0.

### Fixed

- Require the optional modules' stable 0.1 series for development and lock their published 0.1.0 tags, replacing the commits used to prepare their initial releases.
- Identify the core development branch and validation checkouts as `0.2.x-dev`, so the modules' stable `^0.2` core constraint resolves against the current checkout.
- Pin the shared API documentation and tutorial sources to the published module commits and update the displayed stable core version.

## [0.2.0] - 2026-10-13

The PHP 8.3-compatible core and its separately versioned optional modules. This minor release includes API changes described below; the published 0.1 tags keep their PHP 8.5 requirement.

### Added

- A page-reading tutorial using the optional reader's independently generated PDF, with all input pages, verified inherited attributes, ordered content-stream results and the complete PHP reader example. API and source links are pinned to the tested module commit.
- Explicit static TrueType/CID font incorporation with Unicode character maps, metric objects, FontFile2, descriptors, Identity-H Type0 fonts, CIDToGIDMap, widths and ToUnicode streams.
- Explicit sampled-image and DCT image representations, PNG alpha soft-mask construction and Adobe CMYK decoding semantics, composed from the optional image module's bounded readers.
- Frozen file revisions and incremental updates with historical identity aliases, absolute xref offsets, trailer inheritance and changing file identifiers, including replacements of compressed members.
- Dedicated tutorials and rendered PDF examples for fonts, image files and incremental revisions.
- A French GitHub Pages documentation site with a searchable reference of every PHP component, specification constraints and source links, tutorials with generated PDF downloads and browser previews, and automated link and PDF validation before deployment.
- A French tutorial series with explained scripts, rendered previews, experiments and PDF specification references: first document, pages and text, graphics, navigation, links, images, gradients and compressed objects.
- A practical table recipe and guidance for contributing further examples.

### Changed

- Transfer the project to PDFToolkit and separate external-format readers into optional `pdftoolkit/pdf-reader`, `pdftoolkit/pdf-images` and `pdftoolkit/pdf-fonts` packages. Keep PDF objects, metrics, CMaps, image representations and writing in the core, with no production dependency on those modules.
- Compose font descriptors and character maps from explicit PDF values in the core; move TrueType analysis and conversion factories into the font module, and PNG/JPEG parsing into the image module.
- Document all four package APIs centrally, with optional-package badges and source links pinned to each module's locked commit.
- Lower the minimum PHP version to 8.3 while retaining typed constants, readonly classes and the object model; keep the native `NoDiscard` diagnostic when running on PHP 8.5.
- Use PHPUnit 12 and run the quality checks and PDF example validation on PHP 8.3, 8.4 and 8.5; build the documentation with PHP 8.3.
- Run PHPStan on examples and generate and validate every example with qpdf in CI.
- Move the outline hierarchy root to `PDF\Document\OutlineDictionary`, alongside outline items and destinations.
- Rename the catalog entry `Extension` to `Extensions`, matching the PDF key. These API changes have no compatibility aliases.
- Validate document catalogs and resource dictionaries through their specification definitions, including generic entries added at construction or with `with()`.

### Fixed

- Emit free cross-reference records for unused object numbers instead of omitting initial gaps; bound cumulative cross-reference capacity before allocating records.
- Preserve historical reference types and dictionary Type values across incremental replacements; reject reuse of old free numbers, generation changes and replacement of old structural streams.
- Discover references in direct trailer values without writing unnecessary orphan indirect objects.
- Require indirect references for catalog `Dests`, `Outlines` and `Threads`, as specified in table 28. Their specialized entry constructors still check the reference targets.

### Removed

- The unused `PDF\Dictionary\CatalogDictionary` compatibility alias and catalog entry constant registry. Catalog entries now extend the PDF dictionary entry object directly.
- The `PDF\Text\Font` and `PDF\Text\TextObject` convenience wrappers. Compose `BeginTextObject`, `SetTextFontAndSize`, `ShowText` and `EndTextObject` explicitly in a `ContentStreams` object.

### Requirements and compatibility

- PHP 8.3 or later, with `ctype`, `iconv` and `zlib`; CI verifies PHP 8.3, 8.4 and 8.5.
- Optional `pdftoolkit/pdf-reader`, `pdftoolkit/pdf-images` and `pdftoolkit/pdf-fonts` releases begin at 0.1.0 and depend on the core's 0.2 series. The core has no production dependency on these modules.
- Before 1.0, minor releases may change names and signatures. Patch releases preserve the API of their minor series.
- Encryption, signatures, font subsetting, complex-script shaping, external-graph import and linearization remain outside the implemented mechanisms. See [the coverage document](docs/specification.md).

## [0.1.1] - 2026-10-13

Security fixes for the published 0.1 API. The additional API changes and tutorials on master are not part of this maintenance release.

### Fixed

- Reject ambiguous inline-image `EI` sequences accepted without preceding whitespace by tolerant readers.
- Use checked integer arithmetic for inline-image sizes and cross-reference identifier bounds.
- Validate shared direct operand graphs once per identity while retaining active-cycle detection.
- Reject empty/NUL output paths and stream URIs before writing, and report write failures without leaking the destination path.

### Added

- Security regression tests, private vulnerability reporting and a documented trust-boundary policy.
- Locked Composer dependency auditing in CI.

## [0.1.0] - 2026-10-13

First public release of the explicit PDF 1.7 / ISO 32000-1:2008 object model for PHP 8.5.

### Added

- Object-valued PDF primitives, dictionary entries, indirect references and immutable specification definitions, with document-scoped identity instead of a global singleton.
- A complete file writer with byte-accurate stream lengths, cross-reference tables and streams, and compressed object streams.
- Typed content operators, inline images, graphics/text state objects and structural content validation.
- Page trees with inheritance and ownership checks, resources, standard fonts, metadata, destinations, outlines, name trees and number trees.
- All four PDF function representations, including packed samples and typed calculator programs.
- A traceable inventory of 392 tables, 263 dictionary definitions, 1,405 entry classes and 73 content operator tokens, with reproducible generation.
- Executable PDF examples, 147 tests with 7,189 assertions, PHPStan level 8, PSR-12 checks and independent qpdf checks in CI.

### Requirements and compatibility

- PHP 8.5 or later, with `ctype`, `iconv` and `zlib`.
- The 0.x API may change before 1.0.
- This release does not claim complete PDF conformance. Encryption, signatures, font subsetting, specialized image codecs, incremental updates, linearization and additional semantic constraints remain future work. See [the coverage document](docs/specification.md).

[Unreleased]: https://github.com/PDFToolkit/pdf/compare/v0.6.0...HEAD
[0.5.1]: https://github.com/PDFToolkit/pdf/releases/tag/v0.5.1
[0.5.0]: https://github.com/PDFToolkit/pdf/releases/tag/v0.5.0
[0.4.0]: https://github.com/PDFToolkit/pdf/releases/tag/v0.4.0
[0.3.0]: https://github.com/PDFToolkit/pdf/releases/tag/v0.3.0
[0.2.1]: https://github.com/PDFToolkit/pdf/releases/tag/v0.2.1
[0.2.0]: https://github.com/PDFToolkit/pdf/releases/tag/v0.2.0
[0.1.1]: https://github.com/PDFToolkit/pdf/releases/tag/v0.1.1
[0.1.0]: https://github.com/PDFToolkit/pdf/releases/tag/v0.1.0
[0.5.4]: https://github.com/PDFToolkit/pdf/releases/tag/v0.5.4
[0.5.3]: https://github.com/PDFToolkit/pdf/releases/tag/v0.5.3
[0.5.2]: https://github.com/PDFToolkit/pdf/releases/tag/v0.5.2
[0.5.5]: https://github.com/PDFToolkit/pdf/releases/tag/v0.5.5
[0.6.0]: https://github.com/PDFToolkit/pdf/releases/tag/v0.6.0
