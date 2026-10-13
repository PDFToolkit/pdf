# Changelog

## [Unreleased]

### Added

- A French GitHub Pages documentation site with a searchable reference of every PHP component, specification constraints and source links, tutorials with generated PDF downloads and browser previews, and automated link and PDF validation before deployment.
- A French tutorial series with explained scripts, rendered previews, experiments and PDF specification references: first document, pages and text, graphics, navigation, links, images, gradients and compressed objects.
- A practical table recipe and guidance for contributing further examples.

### Changed

- Run PHPStan on examples and generate and validate every example with qpdf in CI.
- Move the outline hierarchy root to `PDF\Document\OutlineDictionary`, alongside outline items and destinations.
- Rename the catalog entry `Extension` to `Extensions`, matching the PDF key. These API changes have no compatibility aliases.
- Validate document catalogs and resource dictionaries through their specification definitions, including generic entries added at construction or with `with()`.

### Fixed

- Require indirect references for catalog `Dests`, `Outlines` and `Threads`, as specified in table 28. Their specialized entry constructors still check the reference targets.

### Removed

- The unused `PDF\Dictionary\CatalogDictionary` compatibility alias and catalog entry constant registry. Catalog entries now extend the PDF dictionary entry object directly.
- The `PDF\Text\Font` and `PDF\Text\TextObject` convenience wrappers. Compose `BeginTextObject`, `SetTextFontAndSize`, `ShowText` and `EndTextObject` explicitly in a `ContentStreams` object.

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
- The 0.x API may change before 1.0 and replaces the unfinished PHP 7.4 prototype.
- This release does not claim complete PDF conformance. Encryption, signatures, font subsetting, specialized image codecs, incremental updates, linearization and additional semantic constraints remain future work. See [the coverage document](docs/specification.md).

[Unreleased]: https://github.com/roukmoute/pdf/compare/v0.1.1...HEAD
[0.1.1]: https://github.com/roukmoute/pdf/releases/tag/v0.1.1
[0.1.0]: https://github.com/roukmoute/pdf/releases/tag/v0.1.0
