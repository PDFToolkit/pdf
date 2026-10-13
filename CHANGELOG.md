# Changelog

## [Unreleased]

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

[Unreleased]: https://github.com/PDFToolkit/pdf/compare/v0.3.0...HEAD
[0.3.0]: https://github.com/PDFToolkit/pdf/releases/tag/v0.3.0
[0.2.1]: https://github.com/PDFToolkit/pdf/releases/tag/v0.2.1
[0.2.0]: https://github.com/PDFToolkit/pdf/releases/tag/v0.2.0
[0.1.1]: https://github.com/PDFToolkit/pdf/releases/tag/v0.1.1
[0.1.0]: https://github.com/PDFToolkit/pdf/releases/tag/v0.1.0
