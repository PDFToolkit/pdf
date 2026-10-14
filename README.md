# PDF

English | [Français](README.fr.md)

An explicit object model of **PDF 1.7 / ISO 32000-1:2008**, with initial
**PDF 2.0 / ISO 32000-2:2020 EC3** mechanisms, for PHP 8.3 or newer.

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

Definitions are based on the Adobe/ISO documents `PDF32000_2008.pdf` and
`adobe_supplement_iso32000.pdf`, retained in this repository. The
[supplement guide](https://pdftoolkit.github.io/guides/adobe-supplement.html)
explains Adobe PDF 1.7 extension level 3, its provenance and mechanisms.
The [Adobe reference guide](https://pdftoolkit.github.io/guides/adobe-references.html)
also presents the 2006 reference, its errata, the redaction addendum and their
portfolio. All Adobe PDFs supplied to the project retain their original names
and bytes; the four archived references are excluded from Composer archives.
The supplied ISO 32000-2:2020 EC3 copy is retained privately; its edition,
physical pages and fingerprint are recorded in a separate catalogue. PDF/A,
PDF/X and PDF/UA profiles require their own conformance mechanisms.

## First PDF 2.0 mechanisms

Core 0.6 adds explicit header/catalog PDF 2.0 declarations, UTF-8 text strings
with BOM, associated files and edition-specific source/correction objects.
PDFDocEncoding and UTF-16BE remain available as separate encoding strategies.
UTF-8 dictionary text is distinct from the font-specific character codes used
in page content. The [PDF 2.0 guide](https://pdftoolkit.github.io/guides/pdf-2-0.html)
and [associated-files tutorial](https://pdftoolkit.github.io/tutorials/27-pdf20-associated-files.html)
explain the construction and its validation.

The ISO 32000-2 pilot contains four tables and 53 entry classes: document
catalog 29, file specification 43, namespace 356 and MCAF property list 409a.
The complete PDF 2.0 catalogue remains pending. Revision 5 encryption is
prohibited for PDF 2.0; its deprecated historical Adobe implementation remains
available for earlier files. Revision 6 encryption, native namespace graphs,
DSS/timestamps, document parts and complete PDF 2.0 conformance are not yet
implemented. Schemas alone do not implement these mechanisms.

## Installation

Core **0.6** requires PHP 8.3 or newer and the `ctype`, `iconv` and `zlib`
extensions. AES and CMS backends are optional and require `openssl`.
[roukmoute/pdf](https://packagist.org/packages/roukmoute/pdf) is available on
Packagist:

```sh
composer require 'roukmoute/pdf:^0.6.1'
```

API changes are recorded in the [changelog](CHANGELOG.md) and
[0.5 migration guide](https://pdftoolkit.github.io/guides/migration-0-5.html);
the [PDF 2.0 guide](https://pdftoolkit.github.io/guides/pdf-2-0.html) describes
the new 0.6 constructions.
To follow development after this series, replace the stable constraint with
`dev-master`.

## Optional modules

| Package | Responsibility |
| --- | --- |
| [`pdftoolkit/pdf-reader`](https://packagist.org/packages/pdftoolkit/pdf-reader) | Read objects and revisions from an external PDF, validate pages and inheritance, inspect their streams. |
| [`pdftoolkit/pdf-images`](https://packagist.org/packages/pdftoolkit/pdf-images) | Inspect JPEG files and decode PNG files into core image objects. |
| [`pdftoolkit/pdf-fonts`](https://packagist.org/packages/pdftoolkit/pdf-fonts) | Parse a static TrueType font and prepare metrics and mappings for core font objects. |

Each module has a stable **0.1.7** release on Packagist, compatible with core
series 0.2, 0.3, 0.4, 0.5 and 0.6 and PHP 8.3 or newer. Composer automatically installs
the core required by the selected module. For example, to import image files:

```sh
composer require 'pdftoolkit/pdf-images:^0.1.7'
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
`COMPOSER_ROOT_VERSION=0.6.x-dev` before `composer install`. This variable
identifies the current core as part of the 0.6 series expected by modules.
Development dependencies use stable 0.1 modules whose exact versions and sources
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
