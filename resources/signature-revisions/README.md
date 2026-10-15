# Protected native revisions

English | [Français](README.fr.md)

Core 0.8.1 closes the ordinary incremental-writing bypass around historical
DocMDP, FieldMDP and usage-rights declarations. Every `IncrementalUpdate` entry
point and direct `IncrementalWriter` operation uses the same conservative guard.
This implements a bounded native workflow, not general validation of arbitrary
signed PDF modifications.

The retained primary references are
[PDF32000_2008.pdf](../reference-documents/PDF32000_2008.pdf), physical pages
478–482, and
[ISO_32000-2_sponsored_EC3.pdf](../reference-documents/ISO_32000-2_sponsored_EC3.pdf),
physical pages 588–592, 606 and 607–608. They require mathematical signature
verification before evaluating subsequent changes. PDF 2.0 excludes necessary
DSS and document-timestamp updates from DocMDP evaluation; that exclusion does
not grant usage rights or loosen an additional Adobe field lock.

## What is enforced

- Native writer output carries an object-scoped emission capability bound to
  that exact `FileRevision`. Constructor-supplied snapshots, clones and PHP
  deserialization are not a substitute for native emission.
- Finalization changes exactly the reserved `Contents` and `ByteRange`. It
  preserves every frozen signature policy and reference. Verified history binds
  the complete final bytes, object identifier, sole Contents gap, format, actual
  padded container and signing credential. Signature-attached MAC is installed
  before the final mathematical verification.
- The guard examines every native historical revision, so removing `Perms`,
  changing `Root`, detaching the AcroForm or renaming a field cannot erase a
  signed restriction. A `SignedRevision` label or opaque `finalize()` result
  supplies no mathematical authority.
- P2/P3 and permitted FieldMDP histories can receive one native signature in an
  existing unsigned field, an unchanged pre-existing reservation, or one newly
  appended terminal field. A complete comparison preserves other objects,
  catalog entries, trailer ownership and encryption. New field/signature
  dependencies, appearances, actions and transforms are deliberately restricted.
- PDF 2.0 can receive a proved document timestamp or explicit DSS update. The
  same complete comparison applies under P1, P2, P3 and FieldMDP-only histories.
  Validation evidence follows a bounded recognized graph; unrelated changes,
  orphan payloads, disguised structural streams, object retirement and reuse of
  historical free numbers are refused. Additional Adobe P1 locks remain binding.

## Migration and explicit boundaries

Keep the actual revision returned by `FileStructure::toRevision()`,
`FileWriter::revision()`, `LinearizedWriter::revision()` or the native signature
workflow. An independently constructed `FileRevision`, even with matching
bytes, is not accepted as incremental input. The constructor remains useful as
explicit metadata; it does not parse or authenticate external PDF history.

`PreparedSignature::signWithPolicy()` and `finalizeWithPolicy()` verify the
actual output with the selected verifier. Low-level `sign()` records verified
history when its selected backend provides both the signing credential and an
actual output verifier. Opaque `finalize()` remains available for byte completion,
but cannot authorize later protected updates. Custom cryptographic verifiers
are explicit application capabilities. Mathematical verification establishes
neither PKI trust, revocation status nor a usage-rights granting identity.

General P2 form filling, page-template instantiation, P3 annotation editing,
appearance regeneration and authenticated reading/classification of external
revision chains remain unimplemented. The guard refuses these unsupported
protected mutations instead of assuming that a permission level makes them
safe. Usage-rights histories cannot be extended until an authorized granting
identity and a complete rights/change intersection are available. The existing
ISO 1.7 and PDF 2.0 construction definitions retain their separate semantics.

## Evidence

[coverage.json](coverage.json) records the exact scope and retained references.
`IncrementalSignaturePermissionsTest` covers the shared entry points, actual
certification/approval signatures, hidden changes, FieldMDP, opaque output and
caller-supplied history. `tools/validate_incremental_permissions.py` compares
real native signatures with unmodified pyHanko and produces independent hostile
incremental updates. CMS mathematics, modification policy and certificate trust
are reported separately. pyHanko's general policy is an independent comparison,
not an assertion that every one of its allowances matches this narrower guard.
