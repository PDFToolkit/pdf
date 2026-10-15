# Migration to core 0.11

English | [Français](fr/migration-0.11.md)

The full guide is maintained in the [central documentation](https://pdftoolkit.github.io/guides/migration-0-11.html).

- PDF2 XMP metadata requires explicit decoded UTF-8 source bytes. Normal compression filters remain available; opaque `EncodedData` metadata is refused. Frozen source-encoding evidence is bound to the actual emitted bytes, including PDF1.7-to-PDF2 catalog upgrades. Replace historical non-UTF8 or opaque metadata explicitly when upgrading; the writer never re-encodes its historical builder. This is an encoding check, not an XMP-schema validator.
- Replace `DocumentPart::addGroup()` with `addChildren()` and explicit parent objects for hierarchy. Move DPart XMP to `DocumentPartRoot`; rename named `nodeNames:` arguments to `entries:`. See the [document-part contracts](../resources/document-parts/README.md).
- A Type3 `FontDescriptor` may omit `FontName`. Its `fontName` property is nullable; call `requiredName()` in code that requires a named descriptor. Other font domains still require a name.
- `Type3Glyph` accepts optional `resources:`. An explicitly empty glyph or font Resources dictionary blocks fallback; omitted dictionaries use glyph → font → inherited page priority, including glyphs called inside a Form. Existing one-argument construction remains valid. Secure redaction still requires an explicit font sanitizer and does not execute arbitrary CharProcs.
- Select `ButtonFieldSemantics::Pdf20` for PDF2 radio appearances without `Off` or pushbutton values. Historical button semantics remains the default. Null text-field values are treated as absent during inheritance while their physical null value is retained.
- Select `SignatureSeedSemantics::Pdf20` for PDF2 signature seed dictionaries; pass an `IntegerType` for `V`. The default Adobe/PDF1.7 semantics retains a real-valued capability. PDF2 native seed entries no longer require an Adobe extension declaration. The frozen writer always checks the actual PDF edition.
- Encrypted UR3 continuation requires an explicit `PasswordUsageRightsAuthentication` or `CertificateUsageRightsAuthentication` on each `UsageRightsAuthorization`, alongside the existing application authority and operation scope. A caller-supplied file key is not a credential. A new signature field also requires `UsageRight::AddField`.
- Rich form filling requires explicit `RichTextFilling` with a bounded parser. Page-template instantiation and indirect text updates remain restricted to proved frozen ownership and historical objects. See [protected revision contracts and limits](../resources/signature-revisions/README.md).

Keep the original native `FileRevision` returned by the writer. Reader revision inspection does not create core permission authority. Legacy UR transforms, arbitrary external revision import, general field-tree editing and unrestricted new appearance resources remain outside this release.
