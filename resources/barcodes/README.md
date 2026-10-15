# Barcode preparation and native appearances

English | [Français](README.fr.md)

## Source and executed procedure

The retained [Adobe supplement](../reference-documents/adobe_supplement_iso32000.pdf),
section 8.6.3, physical pages 45–47, describes barcode text fields and the procedure
that connects their `V`, `DataPrep`, widgets, `PMD` and normal appearance. The
[coverage record](coverage.json) retains its exact identity and execution boundary.
The source PDF is unchanged.

`BarcodeMessagePartition` preserves the bytes already stored in the supplied
string. It never re-encodes text or strips a BOM. With `DataPrep=1` it first
compresses the **whole value once**, using zlib-framed Flate without an image
predictor; only then does it divide the prepared bytes. Each widget receives a
message in order. Message lengths differ by at most one; the first widgets
receive the remainder. Every part exposes its zero-based index, total and byte
offset. Binary parts may split a text-encoding sequence: individual parts are
not new PDF text strings.

An explicit empty value still produces a message for every widget. Its compressed
representation is an ordinary complete zlib stream. No message is silently
dropped when there are more widgets than prepared bytes. An absent `V` requires
an application decision instead of being treated implicitly as an empty value.

## Explicit object construction

```php
$field = new BarcodeField(
    new PartialFieldName(new TextString('barcode')),
    new BarcodeDataPreparation(new IntegerType(1)),
    new TextString('Value chosen by the application'),
);
$plan = new BarcodeFieldPreparation(
    $field,
    new BarcodeLimits(),
    new BarcodeWidgetParameters($firstRectangle, $firstPmd),
    new BarcodeWidgetParameters($secondRectangle, $secondPmd),
);
// $encoder is an explicitly selected BarcodeImageEncoder implementation.
$rendered = $plan->render($encoder);
foreach ($rendered as $rendering) {
    $field->addWidget($rendering->widget);
}
```

The rectangles and `BarcodePaperMetadata` objects are supplied explicitly. The
requests bind the actual stored `V` bytes to those PMD objects in that order;
the same PMD objects are placed in the generated widgets. Constructor-controlled
PMD parameters, including omitted optional `ECC`/`XSymHeight`, cannot be replaced
through generic `Entry` arguments. Captions remain explicit optional entries.
An explicitly encoded value inherited from the actual field ancestor is resolved
through the native field model; no replacement value is inferred from a caption.

Rendering returns `BarcodeWidgetAppearance` objects without attaching them or
changing the field. Attach the widgets in the returned order, bind each to its
actual page and include it in that page's `Annots` and the field's `Kids` through
the normal native builders. The catalog must declare Adobe 1.7 extension level 3
or later. Form defaults and all ordinary field/page ownership rules still apply.

## Encoding capability and accepted images

`BarcodeImageEncoder::encode(BarcodeEncodingRequest): ImageXObject` is an explicit
application capability. The request exposes the message, rectangle, PMD and
processing budgets. Implementing PDF417, QR Code or Data Matrix, their capacity,
error correction, module geometry, quiet zones and any scanner-side multi-symbol
protocol belongs to that encoder. There is no fallback image or fabricated symbol
when it fails, and declaring a PMD symbology does not prove a scannable result.

The native adapter accepts normalized opaque device-colour images. Masks,
alternate images, optional content and ancillary image resource graphs are
outside this adapter's contract. For each accepted image it constructs a native
`FormAppearanceStream` and `WidgetAppearance`: local `BBox=[0 0 width height]`,
`q`, a width/height matrix, `Do`, and `Q`. The image spans the full widget rectangle,
without adding margins or enforcing a quiet zone. Positive extents are computed
from normalized corners, so reversed rectangle coordinates are supported.

## Budgets and field lifecycle

`BarcodeLimits` bounds input/prepared bytes and widget count before partitioning
or invoking the encoder. It also bounds cumulative pixels and raw sample bytes
of all returned images; exhaustion stops before the next encoder call. The
external capability remains responsible for bounding its own work and allocations
inside `encode()`. PDF filters and any explicitly selected encoder are trusted
application capabilities, not a sandbox for arbitrary executable code.

Producing an appearance requires finite, positive normalized rectangle extents.
This operation rejects zero-area geometry without declaring zero-area PDF
rectangles universally invalid. The plan is for a fresh native field without
attached widgets. A changed value requires a newly constructed field/plan;
neither JavaScript calculation nor automatic input observation is executed.

## Widget representation and Acrobat notes

Page 46 permits merging a single widget into its field; page 47 describes that
merged representation during its procedure. The construction keeps both legal
representations: use `addWidget()` for a separate single widget or explicitly
`mergeWidget()` for one merged widget. Multiple widgets stay ordered separate
children. No universal merge requirement is inferred from that wording.

Implementation note E-7, physical page 131, describes Acrobat 9's limit of 255
widgets and specific field flags/background. The default processing budget is
255; it is configurable and does not impose a universal PDF cardinality rule.
No such flags/background or filesystem-derived Caption are silently inserted.
Implementation note E-6, physical page 130, records historical viewer support;
it does not supply a barcode encoding algorithm.

## Evidence and limits

The dedicated unit tests exercise exact binary/BOM data, compression-before-
partition order, empty parts, widget order, PMD identity, reversed/degenerate
rectangles, budgets, encoder failures and separate/merged widgets. Run the oracle:

```sh
python tools/validate_barcode_appearances.py --php php --qpdf qpdf --pdftoppm pdftoppm --work-dir /path/to/output
```

It generates five PDFs with ten widgets and verifies actual `V`, PMD, `Kids`,
`AP.N`, image bytes and complete zlib streams with pypdf/Python. qpdf checks syntax;
Poppler performs 45 pixel checks over the five pages. The image is an announced
two-by-two **test pattern, not a barcode**. This proves preparation, association
and PDF placement, without proving any symbology or successful scan.
