# Deprecated-feature recommendations

[English](README.md) · [Français](README.fr.md)

WTPDF 1.0 §6.2, physical PDF page 10, says that a file **should** avoid
features deprecated by ISO 32000-2. `WtPdfDeprecatedChecks` implements that
recommendation for explicitly recognised relationships in a constructed PDF
graph. It does not turn every deprecated feature into a forbidden writer
operation. WTPDF §8.9.1 separately **forbids** deprecated annotation types;
those existing failures are preserved.

The immutable [catalogue](catalogue.json) records 47 reviewed families with
retained source identities, clauses and physical pages: 34 inspectable in the
constructed graph, six requiring supplied file context, five involving opaque
programs, one consumer behaviour and one source review. It goes beyond the
nonexhaustive introductory list on ISO2 page 14. These counts describe this
catalogue's scope, not a percentage of ISO or WTPDF conformance.

## Inspecting a constructed document

Given the application's constructed `$catalog`, effective `$version` and
`$trailer` dictionaries:

```php
use PDF\Document\Structure\WtPdfAudit;
use PDF\Document\Structure\WtPdfConformanceLevel;
use PDF\Document\Structure\WtPdfDeprecatedFileContext;

$report = (new WtPdfAudit())->inspect(
    $catalog,
    $version,
    WtPdfConformanceLevel::Accessibility,
    new WtPdfDeprecatedFileContext($trailer),
);

$failures = $report->failures();
$recommendations = $report->recommendations();
$pendingHumanOrExternalChecks = $report->pending();
$report->assertNoDetectedFailures();
```

`Recommendation` is distinct from `Failed`. Recommendations do not make
`assertNoDetectedFailures()` throw. `pending()` contains human and external
checks, while `recommendations()` returns the discouraged features detected.
Applications that previously displayed every non-failure as pending should
display these two categories separately.

The existing three-argument `inspect()` call remains valid. Without the fourth
argument, a finding explicitly states that document `Info` and encryption
declarations have not been inspected. Supplying an empty trailer represents
the application's assertion that those entries are absent. The context is
input about the constructed model; it does not authenticate imported file
bytes, validate a CMS signature, or reconstruct historical revisions.

## Context and exceptions

The traversal follows standard relationships: actual pages and their
annotations, resources and their fonts/XObjects/graphics states, form fields
and signature values, actions reached through standard triggers, structure
attributes, associated files and embedded-file name trees. An object may be
visited in several domains; a recommendation for the same object and feature
is reported once. Object, edge, depth and typed-operation budgets are explicit
`WtPdfDeprecatedLimits` values. An exhausted budget produces an incomplete
external-check finding, never a conformance failure or inferred pass.

Private dictionaries are not guessed to be actions, fonts, file specifications
or encryption dictionaries. `Name`, `R`, `Info`, `ID` and `Mac` are interpreted
only in their actual domains. In particular:

- Trailer `Info.CreationDate` and `Info.ModDate` remain valid; a thread's
  information dictionary is a different use of `Info` metadata.
- Type1 `CharSet` and CID-font `CIDSet` are checked in their respective
  descriptors. `BaseFont` and ordinary resource names are not deprecated
  `Name` entries.
- Image-mask `OPI` and attempted `CF.Identity` redefinitions are ignored as
  specified. A form's private `ID` is not an image/page Web Capture ID.
- Graphics-state `TR`/`TR2` differ from halftone transfer functions. Only the
  standard events for an additional-actions dictionary's owner create action
  relationships.
- Lowercase `checked` is detected for an explicit `PrintField` attribute
  owner; `Checked` remains current. Determining equivalent standard semantics
  in custom owners or namespaces requires additional semantic review.
- The crypt-filter `Length` entry is listed as deprecated **and required** in
  ISO2 Table25. The recommendation explicitly preserves that conflict and
  does not advise deleting required data automatically.

The independent `WtPdfDeprecatedChecks::inspect()` entry point returns the
same finding objects when only these checks are required. It is not a general
PDF validator. Native writing remains available for historical mechanisms.

## Explicit boundaries

Typed content instructions expose the obsolete `F` operator. Opaque stream
bytes are not searched for the letter `F`: a content parser is required to
distinguish operators from strings, comments and inline image data. CMap
programs and untyped embedded FDF payloads also require format parsers.

Signature CMS digests, certificate algorithms, public-key envelope algorithms
and encrypted recipient permissions require explicitly selected cryptographic
validation. Dictionary declarations do not reveal every algorithm or prove
that a consumer honours permissions. Those cases remain external findings.

The appended EC3 issue133 diff on physical page1010 contains a Link grouping
deprecation note. Its approval chain and semantic applicability have not been
promoted into an executable rule by this catalogue. The row remains
`source-review-required`. Catalogue registration does not resolve an EC3
annotation or establish a universal inventory of deprecated features.

Regression evidence: [WtPdfDeprecatedFeaturesTest](../../../tests/WtPdfDeprecatedFeaturesTest.php)
checks all 40 mechanically inspectable families, domain collisions, shared
objects, cycles, inherited signature-field types, opaque boundaries, budgets
and the distinction between recommendations and existing SHALL failures.
