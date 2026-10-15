# Embedded-file placement

`EmbeddedFilePlacementValidation` applies EC3 issue #481 from
`ISO_32000-2_sponsored_EC3.pdf`, physical pages 153 and 656, to the effective
PDF 2.0 document graph. The source remains unchanged in `reference-documents`;
its SHA-256 is `90f4b666d46405dd62630a98d514158b4de5304b749523c4081680ac4fe2e230`.

An embedded-file stream is referenced through the `EF` or `RF` entries of a
file specification. A private dictionary merely spelling a key `EF` does not
acquire this role. The validator also recognizes an embedded stream whose
optional `Type` is absent, once a file specification identifies its role.
A second reference from another dictionary or array is rejected.

File specifications can be associated with any appropriate document object;
the catalog `EmbeddedFiles` name tree is not generally required. Each
`FileAttachment` annotation must identify an indirect file specification with
an actual embedded stream. Related-file filename/stream pairs remain valid.

Incremental validation uses the frozen effective graph and preserves prior
bytes. Old objects no longer reachable from the catalog do not represent active
attachments. Validation is bounded to one million graph positions by default;
it does not open attachment content or retrieve external files. Historical
PDF 1.x interpretation is left separate.

The executable tests are `tests/EmbeddedFilePlacementTest.php`. Existing metadata,
associated-file and RichMedia fixture oracles independently inspect permitted
file-specification ownership and embedded bytes.
