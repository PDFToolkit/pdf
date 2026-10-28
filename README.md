# PDF

English | [Français](README.fr.md)

An explicit object model and writer for **PDF 1.7 / ISO 32000-1:2008** and
**PDF 2.0 / ISO 32000-2:2020 EC3**, with companion specifications for encryption,
signatures, metadata and tagged structure. Requires PHP 8.3 or newer, 64-bit.

This library represents the format's syntax and structures down to names,
numbers, references, dictionary entries and operators. It does not provide a
layout API. A higher-level library can construct these objects while retaining
control over their composition.

The project belongs to the [PDFToolkit](https://github.com/PDFToolkit)
organisation. This repository is the **core**: it contains the objects and
mechanisms described by the Adobe/ISO standard, including writing. Reading
external PDFs, converting PNG/JPEG files and parsing TrueType fonts belong to
three optional packages. Each module depends on the core; installing the core
to generate a PDF does not install any of these modules.

The [online documentation](https://pdftoolkit.github.io/) brings together guides,
the reference for every component and tutorials with code and viewable PDFs.
It is maintained in [PDFToolkit/pdftoolkit.github.io](https://github.com/PDFToolkit/pdftoolkit.github.io),
using published versions pinned to their exact commits. Pages identify package
versions and the documentation commit separately.

The `0.x` series is intended for developers building and exploring this model.
Before version `1.0`, a new minor version may change names and signatures;
patch versions preserve their series' API. Implemented scope and limitations
are listed in the [specification coverage](https://pdftoolkit.github.io/guides/specification.html).

All eighteen original reference PDFs are kept in
[`resources/reference-documents/`](resources/reference-documents/README.md),
with their original filenames and bytes. Definitions are based on the Adobe/ISO
documents [PDF32000_2008.pdf](resources/reference-documents/PDF32000_2008.pdf) and
[adobe_supplement_iso32000.pdf](resources/reference-documents/adobe_supplement_iso32000.pdf). The
[supplement guide](https://pdftoolkit.github.io/guides/adobe-supplement.html)
explains Adobe PDF 1.7 extension level 3, its provenance and mechanisms.
The [Adobe reference guide](https://pdftoolkit.github.io/guides/adobe-references.html)
also presents the 2006 reference, its errata, the redaction addendum and their
portfolio. All Adobe PDFs supplied to the project retain their original names
and bytes; reference PDFs are excluded from Composer archives.
The supplied [ISO 32000-2:2020 EC3 PDF](resources/reference-documents/ISO_32000-2_sponsored_EC3.pdf),
obtained through the [PDF Association sponsored access](https://www.pdfa.org/sponsored-standards/),
is retained unchanged in this repository and excluded from Composer archives.
Its edition, physical pages and fingerprint are recorded in a separate catalogue. PDF/A,
PDF/X and PDF/UA profiles require their own conformance mechanisms.

Version 0.12 strengthens page, outline, action and RichMedia contracts, adds bounded Type 4 calculator evaluation and preserves imported image rendering intents. See the [migration notes and boundaries](docs/migration-0.12.md).

## PDF 2.0 companion specifications

Core 0.7 adds R=6 AES-CBC and R=7 AES-GCM, SHA-3/SHAKE256 and named-curve
signatures, PDF MAC integrity protection, namespace-qualified structure and
TS 32005 inclusion rules. XMP declarations, object metadata, black point
compensation, document parts and encrypted-payload wrappers are explicit objects.
RFC3161 timestamps and DSS/VRI bind validation evidence to actual signed
revisions. Geospatial objects describe coordinate systems, viewports, paired
control points, affine matrices and point clouds.
Structure destinations target actual logical elements, with ordered content
resolution, explicit page fallbacks and separate remote byte-string IDs.
The [bundle guide](https://pdftoolkit.github.io/guides/pdf-2-0-bundle.html)
explains each source, algorithm, object relationship and independent check.

The eleven supplied PDFs are preserved unchanged in
[the reference collection](resources/iso32000-2-bundle/README.md), with their
PDF Association provenance, byte identities, physical pages and annotations.
They are excluded from Composer distributions. The earlier ISO 32000-2 copy
remains available separately; different copy hashes do not imply different editions.

The [PDF 2.0 guide](https://pdftoolkit.github.io/guides/pdf-2-0.html) explains
file versions, Unicode text strings and associated files. The tutorials add
[metadata and declarations](https://pdftoolkit.github.io/tutorials/28-pdf20-metadata-declarations.html),
[encrypted wrappers](https://pdftoolkit.github.io/tutorials/29-pdf20-encrypted-payload.html),
[structure namespaces](https://pdftoolkit.github.io/tutorials/30-pdf20-tagged-namespaces.html)
[modern signatures](https://pdftoolkit.github.io/tutorials/31-pdf20-modern-signature.html),
[timestamps and DSS](https://pdftoolkit.github.io/tutorials/32-pdf20-timestamp-dss.html)
[geospatial measurements](https://pdftoolkit.github.io/tutorials/33-pdf20-geospatial.html)
and [structure destinations](https://pdftoolkit.github.io/tutorials/34-pdf20-structure-destinations.html),
with executable sources and actual PDF previews.

A `WtPdfAudit` report distinguishes detected failures, additional validation and
human semantic review. A metadata declaration does not establish its own truth.
The specification catalogue, native mechanisms and full document conformance
remain separate concerns; [coverage](https://pdftoolkit.github.io/guides/specification.html)
records their actual scope. Revision 5 encryption is prohibited for PDF 2.0.
The edition-specific base inventory contains 440 tables, 306 dictionary definitions
and 1,709 entry objects. Its complete inventory does not establish every semantic
or conditional requirement of the standard.

## Executable constraints and applied redaction

Version 0.8 adds 197 intrinsic rules executed by specification dictionaries,
the exact EC3 #246/#403/#444/#468 corrections and a ledger of all 65 Adobe
corrections. Sources, dispositions, evidence and remaining boundaries are
recorded beside the [Adobe references](resources/adobe-references/README.md)
and [semantic rules](resources/iso32000-2-2020/README.md).

[Applied redaction](resources/redaction/README.md) removes affected instructions
and resources from a normalized native graph, then writes a fresh file. Opaque
unnormalized cases are rejected; regions are not merely covered. An explicit
WTPDF XML backend also checks plain/rich-text value equivalence. These additions
do not establish universal conformance or accessibility certification.

`ChoiceField` now accepts `ChoiceIndexSemantics` before variadic entries.
Historical behaviour remains `Pdf17`; `Pdf20Ec3` selects corrected PDF 2.0
indices. Calls passing positional `Entry` values must supply that argument
before them. `AnnotationAppearanceTransform` executes the corrected
`Matrix × A` appearance-placement algorithm.

## Contextual validation, barcode appearances and font usage

Version 0.9 validates annotation ownership and markup relationships, remote and
embedded action targets, and rectilinear measurement/number-format arrays in
their actual graph context. See the source-linked
[graph checks](resources/graph-semantics/coverage.json) and their counterexamples.
These checks do not execute actions or open external files.

[Barcode preparation](resources/barcodes/README.md) preserves the actual field
bytes, optionally compresses the whole value before dividing it among widgets,
and constructs full-widget normal appearances through an explicit image-encoding
capability. The barcode symbology, capacity and scanner behavior belong to the
selected external encoder.

WTPDF font-usage checks follow only the selected managed Type3 glyph programs,
with local resources and cumulative budgets. `Type3Glyph(resources: ...)` declares
glyph-local resources; EC3 #128 resolves glyph, font, then inherited page resources
without merging dictionaries. Frozen graph validation uses the same priority.
The five-file resource oracle checks qpdf/pypdf and two renderers; it records
Poppler's differing fallback inside a Form while Ghostscript confirms the page
resource rule. Unused glyph programs do not create
false embedding failures. ISO 32000-2 excludes Type3 text rendering mode 7 from
rendering; retained Poppler/PDFium versions behave differently, so the independent
oracle records this limit rather than claiming visual confirmation for that mode.
Deprecated PDF 2.0 features are inspected in their normalized graph domains.
`WtPdfFindingStatus::Recommendation` and `recommendations()` distinguish WTPDF
SHOULD recommendations from failures and pending human/external validation.
Provide `WtPdfDeprecatedFileContext` explicitly for trailer/encryption context;
it is caller-supplied evidence, not authenticated source provenance.

`PdfMacTokenVerifier` verifies tokens using an explicit file key and KDF salt,
without a random-source dependency. SHA-2 identifiers accept equivalent absent
or NULL parameters; SHA-3 parameters remain absent. Historical
`Revision5Permissions` can explicitly represent required MAC policy. Source-file
authentication is implemented by the optional reader, not by the core parser.

Version **0.9.1** enforces PDF2 annotation appearance requirements in the effective
page graph, including frozen catalog upgrades. Only point rectangles (both
dimensions zero), Popup, Projection and Link are exempt from AP; PDF1.7 remains
optional. AP.N, Form streams and required AS names are checked while preserving
the source-defined fallback for an unmatched state. The [appearance guide](resources/annotation-appearances/README.md)
records EC3 #22/#124: their exact approval chains are recorded in the semantic review register,
separate from the intrinsic rules. No appearance is generated implicitly.

## Protected incremental revisions

Version 0.10.0 extends the shared native guard to bounded P2/P3
filling of existing text, choice, checkbox and radio fields, and P3 editing of
listed markup annotations. It compares the complete frozen delta and preserves
historical DocMDP, FieldMDP, Adobe locks, field inheritance and shared resources.
Variable-text appearances preserve bytes outside an isolated `/Tx` region;
buttons keep their existing appearance programs. Terminal signing and PDF 2.0
DSS/document-timestamp maintenance retain their separate restricted proofs.

Native UR3 histories require explicit per-update `UsageRightsAuthorization`:
an application authority must recognize the verified signing identity, and each
operation must satisfy signed rights, selected scope, saving conditions and all
other historical restrictions. Mathematical validity alone grants no usage
rights or PKI trust. PDF 2.0 nonrestrictive UR keeps explicit authority and
operation scope; restrictive UR follows its distinct FullSave rules.
Encrypted sources also require explicit password/certificate authentication and the intersection of actual security-handler permissions. Legacy UR remains unsupported. FileSelect filling and rich-text V/RV updates with an explicit
XML interpreter are supported, with complete rich-text appearance renewal.
Invisible template pages without annotations/fields can also be instantiated
with their historical contents/resources. New resources, external revision-chain
classification and mixed fill/signing/maintenance updates remain outside scope.
See the [supported operations, migration and independent evidence](resources/signature-revisions/README.md).

Keep the original `FileRevision` returned by native writing or signing in the
current runtime. A manually reconstructed or cloned revision has no native
provenance, and native evidence cannot be restored through PHP serialization.
`PreparedSignature::finalize()` can embed externally supplied bytes, but opaque
bytes provide no verified authority for a later protected revision. Continuing
such a history requires actual output verification bound to the final bytes,
signature dictionary, byte range and signing credential. This proves mathematical
integrity, not certificate trust or a general classifier for changes to external PDFs.

Applied redaction also validates retained font entries within their actual
simple, composite or CID font domain. Superfluous CID entries on a simple font
are rejected rather than copied into the fresh output. Opaque embedded programs
still require an explicit sanitizer; see the [redaction boundaries](resources/redaction/README.md).

## Installation

Core **0.12** requires 64-bit PHP 8.3 or newer and the `ctype`, `iconv` and `zlib`
extensions. AES and CMS backends are optional and require `openssl`. The explicit
Ed25519/Ed448 provider additionally receives a local OpenSSL executable.
[roukmoute/pdf](https://packagist.org/packages/roukmoute/pdf) is available on
Packagist:

```sh
composer require 'roukmoute/pdf:^0.12'
```

API changes are recorded in the [changelog](CHANGELOG.md) and
[0.5 migration guide](https://pdftoolkit.github.io/guides/migration-0-5.html);
the [PDF 2.0 guide](https://pdftoolkit.github.io/guides/pdf-2-0.html) describes
the foundational constructions; the bundle guide covers 0.7.
To follow development after this series, replace the stable constraint with
`dev-master`.

## Optional modules

| Package | Responsibility |
| --- | --- |
| [`pdftoolkit/pdf-reader`](https://packagist.org/packages/pdftoolkit/pdf-reader) | Read objects and revisions from an external PDF, validate pages and inheritance, inspect their streams. |
| [`pdftoolkit/pdf-images`](https://packagist.org/packages/pdftoolkit/pdf-images) | Inspect JPEG files and decode PNG files into core image objects. |
| [`pdftoolkit/pdf-fonts`](https://packagist.org/packages/pdftoolkit/pdf-fonts) | Lay out OpenType glyphs and embed static TrueType or CFF fonts using core font objects. |

Images **0.2.0**, Fonts **0.2.0** and Reader **0.7.0** accept core 0.12 and PHP 8.3 or newer. Composer automatically installs
the core required by the selected module. For example, to import image files:

```sh
composer require 'pdftoolkit/pdf-images:^0.2'
```

The module installs the core using its stable constraint. The
[package guide](https://pdftoolkit.github.io/guides/packages.html) explains their
boundaries, installation and reader operation. Keep the application's
`composer.lock` to pin the selected versions and commits.

## Development and checks

From a repository clone:

```sh
composer install
composer check
php examples/hello-world.php hello-world.pdf
php examples/object-stream.php object-stream.pdf
```

Production code depends only on PHP and these three extensions. PHPUnit 12,
PHPStan, PHP-CS-Fixer and modules used by examples are development dependencies
locked in `composer.lock`. Their presence in this checkout adds no production
dependencies to applications using the core. CI runs tests and validates example
PDFs under PHP 8.3, 8.4 and 8.5. Tests also fail on PHP deprecations and warnings.
The [changelog](CHANGELOG.md) describes every published version.

To validate this checkout, including from its tag, set
`COMPOSER_ROOT_VERSION=0.12.x-dev` before `composer install`. This variable
identifies the current core as part of the 0.12 series expected by modules.
Development dependencies use stable published modules whose exact versions and sources
are locked in `composer.lock`. CI also sets this variable for branches, tags
and pull requests.

## Object-by-object construction

```php
$helvetica = new Type1Font(StandardFont::Helvetica);
$resources = new ResourceDictionary(
    new Font(new DictionaryType(
        new Entry(new NameType('F1'), new IndirectReference($helvetica)),
    )),
);
$contents = new StreamObject(new ContentStreams(
    new BeginTextObject(),
    new SetTextFontAndSize(new NameType('F1'), new NumericType(18)),
    new MoveTextPosition(new NumericType(72), new NumericType(720)),
    new ShowText(new LiteralString('Super !')),
    new EndTextObject(),
));
$page = new PageObject(
    new Rectangle(new NumericType(0), new NumericType(0), new NumericType(612), new NumericType(792)),
    $resources,
    $contents,
);
$pages = (new PageTreeNode())->addKid($page);
$catalog = new DocumentCatalog($pages);
$file = new FileStructure(new FileHeader('1.7'), new FileBody($catalog));
$file->writeTo('hello-world.pdf');
```

The complete example, with imports and metadata, is in
[`examples/hello-world.php`](examples/hello-world.php).

## Tutorials and recipes

The [tutorial path](https://pdftoolkit.github.io/tutorials/index.html) accompanies
each script with an explanation, preview and variations to try. Code comments
connect objects to PDF instructions and structures. Documentation is available
in English and French.

| Topic | What the example explains |
| --- | --- |
| [First document](https://pdftoolkit.github.io/tutorials/01-first-document.html) | Font, resources, contents, page, catalog and document information. |
| [Pages and text](https://pdftoolkit.github.io/tutorials/02-pages-and-text.html) | Resource inheritance, multiple pages, positions and leading. |
| [Drawing](https://pdftoolkit.github.io/tutorials/03-graphics.html) | Colours, paths, curves and graphics-state transformations. |
| [Navigation](https://pdftoolkit.github.io/tutorials/04-navigation.html) | Hierarchical bookmarks and destinations targeting pages. |
| [Links](https://pdftoolkit.github.io/tutorials/05-links.html) | Internal clickable annotations and URI actions. |
| [Images](https://pdftoolkit.github.io/tutorials/06-images.html) | Image XObject, inline image, RGB bytes and filters. |
| [Gradient](https://pdftoolkit.github.io/tutorials/07-gradient.html) | Type 2 PDF function and axial shading. |
| [Compressed objects](https://pdftoolkit.github.io/tutorials/08-object-stream.html) | Content streams, object container and cross references. |
| [TrueType font and Unicode](https://pdftoolkit.github.io/tutorials/09-embedded-font.html) | Embedded program, CIDs, widths and ToUnicode mapping. |
| [JPEG and PNG images](https://pdftoolkit.github.io/tutorials/10-image-files.html) | Colours, PNG decoding, preserved JPEG data and transparency mask. |
| [Incremental revisions](https://pdftoolkit.github.io/tutorials/11-incremental-update.html) | Explicit replacements, preserved historical bytes and chained xrefs. |

The [table recipe](https://pdftoolkit.github.io/recipes/table.html) combines these
concepts in a concrete presentation. The [recipe collection](https://pdftoolkit.github.io/recipes/index.html)
also explains how to contribute new examples.

## Identity and responsibilities

- PDF values are objects. Strings represent bytes; `TextString` explicitly
  encodes Unicode text.
- An `IndirectReference` targets an instance. A `SerializationContext` assigns
  identifiers while writing one file. There is no singleton or global counter.
- `FileStructure` describes the file; `FileWriter` writes it. Each write creates
  a fresh context and derives offsets and lengths from bytes actually emitted.
- Dictionaries use `Entry` objects. `with()` and `without()` construct copies
  and preserve specialised model constraints.
- Page trees and outlines use mutable graph builders with one parent and
  explicit references. Their structure calculates links and counts; standard
  definition objects are immutable.
- Filters apply in the reverse of their decoding order. Streams are always
  referenced as indirect objects.

See the [architecture guide](https://pdftoolkit.github.io/guides/architecture.html)
for construction choices and [standard coverage](https://pdftoolkit.github.io/guides/specification.html)
for traceability and limits.

The [component guide](https://pdftoolkit.github.io/guides/components.html) explains
each object family. The [API reference](https://pdftoolkit.github.io/reference/index.html)
provides signatures and links to code and the standard. The
[site build instructions](https://github.com/PDFToolkit/pdftoolkit.github.io/blob/main/docs/site/README.md)
describe generation and local checks in the documentation repository.

## Coverage and limits

The ISO 32000-1 catalogue records **392 tables**, **823 indexed sections**
and **73 operator tokens**. Dictionary tables produce named classes for their
dictionaries, entries and immutable definitions. PDF names preserve their exact
case, which is significant in the format.

Executable implementations cover fundamental objects, file writing,
cross-reference tables and streams, compressed objects, content operators,
page/name/number trees, destinations and outlines, standard fonts and all four
PDF function representations.

**The presence of a definition is not a declaration of complete conformance.**
The catalogue validates types, required references, unconditional requirements
and certain value forms. Conditions, relationships between dictionaries and
version rules that do not yet have specialised validation remain in their
definitions and the coverage document.

The core composes font objects, CMaps, images and masks, forms, tagged structure
and incremental revisions. It also writes filters, explicitly selected
encryption, byte-range signatures, hybrid references and linearisation. Optional
modules provide TrueType analysis, JPEG/PNG conversion and external PDF reading.
Verified mechanisms and remaining rules are listed in the
[coverage inventory](https://pdftoolkit.github.io/guides/mechanisms.html).
Font program subsetting, complex scripts and importing an external graph with
remapping require dedicated components. `EncodedData` explicitly declares
already encoded bytes. Unsupported security requirements are rejected.

The writer currently assembles the file in memory. The reader is provided by
`pdftoolkit/pdf-reader`. A rendering engine and layout API are outside the core.

## Security

The [security policy](SECURITY.md) describes private reporting and trust
boundaries. The [security review](https://pdftoolkit.github.io/guides/security.html)
explains serialisation protections and application responsibilities for paths,
PDF actions and resource limits.

## Definition generation

Structured resources and generated classes are versioned. Generation is
reproducible from these resources; it does not reread the PDF at library runtime.

```sh
python tools/generate_specification.py
```

The `tools/extract_spec.py` extractor retains the source and PDF locations.
It requires the Python dependencies listed in the specification documentation.
Normative descriptions trace constraints still to be implemented; class
generation does not replace algorithms.
