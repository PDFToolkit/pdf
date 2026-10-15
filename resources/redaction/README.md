# Applied redaction

[English](README.md) · [Français](README.fr.md)

The retained Adobe source is [pdf_reference1-7_addendum_redaction.pdf](../reference-documents/pdf_reference1-7_addendum_redaction.pdf), November 2006. Physical pages 2–4 distinguish marking a region from actually removing its content. Its unchanged SHA-256 is recorded in [coverage.json](coverage.json).

`PDF\Document\Redaction\SecureRedaction` applies a `RedactionPlan` to an explicit native `DocumentCatalog`. Each `RedactionPageRequest` binds the actual `PageObject` to its `RedactionAnnotation` values. The executable [example](../../examples/pdf17-secure-redaction.php) constructs two pages, applies one redaction, and writes a new file.

```php
$result = (new SecureRedaction())->apply(
    $catalog,
    new RedactionPlan(new RedactionPageRequest($page, new RedactionAnnotation($annotation))),
);
$result->writeTo($destination);
```

An annotation identifies content. `SecureRedaction` removes source paint instructions and affected image/Form payloads before adding a replacement. It never treats a black rectangle, clipping or an image mask as deletion. Every affected shared image/Form and its render-resource closure is excluded from all uses, including pages processed earlier. Dependents that reference an affected resource through `Mask`, `SMask` or a Form are also excluded before any final page is rebuilt. This deliberately permits excess deletion.

`Rect` is normalized; `QuadPoints` takes precedence and uses conservative quadrilateral hulls. Native path control-point hulls, all four transformed rectangle corners, CTMs and conservative stroke expansion determine intersection. Unpainted paths terminated by `n` are removed too when they retain geometry inside a region. An affected image or Form is removed as an indivisible object. Text is removed as a whole `BT`/`ET` object. By default all text objects on a selected page are removed, because a declared `FontBBox` or `Widths` cannot prove the actual font-program geometry. An explicitly injected `RedactionFontGeometry` may supply actual program-derived outline hulls and verified advances to preserve text proven outside every region. The core performs no implicit font loading.

After removal, `IC` supplies the RGB replacement fill; absent `IC` is transparent. `RO` takes precedence over `IC`, `OverlayText`, `DA` and `Q`, and its Form origin is aligned with the annotation rectangle. Native `OverlayText` accepts a typed `DefaultAppearance`, explicit simple-font widths or standard Courier, Unicode mappings supplied by a native `ToUnicodeCMap`, justification `Q` and `Repeat`. Complex shaping or a different compositor can be supplied as a normalized `RO` Form. Opaque appearance strings are rejected until normalized; no parser guesses an unsupported font encoding.

The result exposes only fresh-file writing, not incremental updates. Its rebuilt catalog/page graph intentionally drops all annotations, structure/alternative-text channels, XFA/forms, document/object metadata, attachments, actions, outlines, information dictionaries and prior revisions. Necessary render resources are pruned and copied through context-specific whitelists with validated value shapes. Ancillary font descriptor fields, including `CharSet`, `FontFamily` and `Style`, are discarded; a string hidden under a numeric key is rejected. Unused fonts and `ToUnicode` alternatives are removed. Logical structure, interactive forms and original text extraction are therefore not preserved as accessibility claims.

Strict defaults retain only exact normalized device-color pixel rows. Opaque encoded images, trailing image bytes, unnormalized profiles and retained embedded/complex font programs require an explicit `RedactionResourceSanitizer` that parses and rebuilds those formats. The core supplies no such external-format parser. Affected opaque images can be deleted without decoding. This is not a steganography detector or a search for every occurrence of a secret string.

Font entry domains are checked separately for simple, Type0 composite and CID descendant subtypes. A simple font cannot carry `CIDSystemInfo` or `DescendantFonts` as an unused string channel. A normalized Type0 font has one CID descendant; identifiers such as its CID `Registry` and `Ordering` remain necessary rendering data. The selected font sanitizer remains responsible for external font-program semantics; this check does not infer those semantics from declared names or bounds.

Unsupported content is rejected before a result can be written: opaque page/Form content; unknown operators; unnormalized `gs`, patterns/shadings, optional-content visibility, externally referenced XObjects; inherited Form text state without a local `Tf`; text clipping; and clipping paths that cross a redaction. These cases require explicit normalization or a separate processor. Budgets limit instructions across both security passes and dependency traversal, including repeated graph edges, graph objects, nesting, pixel bytes and replacement repetitions. This API is a conservative processor for the documented normalized graph, not an arbitrary-PDF editor.

Validation includes negative regressions for untrusted font bounds and opaque/trailing image metadata, shared-resource deletion, RO/IC precedence, replacement state isolation and bounded processing. `tools/validate_secure_redaction.py` independently checks all reachable objects and decoded streams with pypdf, checks both original and result with qpdf, and renders sixteen pages with Poppler, including four rotation/shear rectangle cases and two shared Mask/SMask dependency cases. It verifies that deleted text/image bytes and semantic metadata disappear, outside vectors survive, and there is only one output revision.
