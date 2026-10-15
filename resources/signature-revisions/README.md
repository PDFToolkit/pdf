# Protected native revisions

English | [Français](README.fr.md)

The unreleased 0.10.0 candidate extends the common incremental guard introduced
in 0.8.1. Every `IncrementalUpdate` entry point and direct `IncrementalWriter`
operation compares the complete frozen delta against authenticated historical
DocMDP, FieldMDP, Adobe field locks and usage rights. Supported native operations
are bounded; this is not a general classifier for arbitrary signed PDFs.

The retained primary references are
[PDF32000_2008.pdf](../reference-documents/PDF32000_2008.pdf), physical pages
440–444, 478–482 and 485;
[ISO_32000-2_sponsored_EC3.pdf](../reference-documents/ISO_32000-2_sponsored_EC3.pdf),
physical pages 545–549, 560, 588–592, 606 and 607–608; and
[adobe_supplement_iso32000.pdf](../reference-documents/adobe_supplement_iso32000.pdf),
physical page 43. [coverage.json](coverage.json) records exact source identities
and SHA-256 values. Mathematical signature verification precedes permission
assessment. Each actual operation must satisfy every applicable restriction;
usage rights never override DocMDP or a field lock.

## Native provenance and complete comparison

Keep the actual revision returned by `FileStructure::toRevision()`,
`FileWriter::revision()`, `LinearizedWriter::revision()` or native signing.
Emission authority belongs to that exact `FileRevision` in the current runtime.
Constructor-supplied snapshots, clones and PHP deserialization cannot recreate
it, even from identical bytes. Removing `Perms`, replacing `Root`, detaching the
AcroForm or renaming a field cannot erase historical restrictions.

Finalization changes exactly reserved `Contents` and `ByteRange`. Verified
history binds all final bytes, the signature identity, its sole Contents gap,
format, padded container and actual signing credential. Signature-attached MAC
is installed before final mathematical verification.
`PreparedSignature::signWithPolicy()` and `finalizeWithPolicy()` verify actual
output. Low-level `sign()` supplies verified history when its selected backend
provides the signing credential and an output verifier. Opaque `finalize()` only
completes bytes; it cannot authorize subsequent protected updates.

Every classified delta preserves unrelated catalog, page, content, trailer,
field and object data. Shared references are checked by actual ownership paths;
a private alias under an otherwise legitimate owner is not ownership. New
objects must belong to the proved operation. Orphan payloads, object retirement
and reuse of historical free numbers are refused. Existing shared appearance
streams and resources remain immutable; replacement appearances use fresh
object identities and unchanged historical resources.

## Supported forms and annotations

- Under P2/P3, fill existing terminal `Tx`, `Ch` and non-pushbutton `Btn` fields.
  Effective inherited values and flags are inspected. Identities, names, tree
  ownership, defaults, options, actions and widget geometry stay fixed. ReadOnly
  and historical FieldMDP/Adobe locks remain binding, including descendants
  protected through a nonterminal field. Qualified names must be unambiguous;
  renaming and reparenting are refused.
- Plain text respects `MaxLen` and comb constraints. Choice values and `I`/`TI`
  agree with fixed options and the PDF version. Checkbox/radio values agree with
  widget `AS` and existing Off/on appearances; filling cannot replace those
  button appearance programs.
- Non-rich variable-text appearance replacement preserves geometry and every
  decoded byte outside one `/Tx BMC`…matching `EMC` region; if absent, append it.
  Drawing/state instructions own their `q`/`Q` isolation. Paths, pending clipping
  and text objects cannot cross the region or graphics-restoration boundaries.
  The lexer handles escaped strings/names, comments and containers. Plain and
  one `FlateDecode` stream are supported, with default limits of 1 MiB encoded
  and decoded bytes, 100,000 tokens and nesting 64. Multiple/nested Tx, inline
  images, unknown/Type3 operators and predictors are refused. This proves
  region/state boundaries, not full graphics operand conformity, rendered-value
  equivalence or human intent. Resource names keep their previous bindings;
  added bindings only select unchanged historical objects. Existing appearance
  alternatives and state names are retained; adding R/D alternatives or states
  without a comparable historical program is refused.
- P3 additionally permits bounded creation, modification and detachment of
  `Text`, `FreeText`, `Line`, `Square`, `Circle`, `Polygon`, `PolyLine`,
  `Highlight`, `Underline`, `Squiggly`, `StrikeOut`, `Caret`, `Ink` and `Stamp`
  annotations. Page ownership and surviving annotation order stay fixed.
  ReadOnly, Locked and LockedContents prevent editing. Widgets, actions, new
  relationships and arbitrary dependencies are outside this annotation scope.
  Detachment preserves the historical object bytes.

The established single-signature workflow remains available under P2/P3 and
permitted FieldMDP histories: an existing unsigned field, unchanged reservation
or one new terminal signature field, with the complete restricted delta proof.
PDF 2.0 permits proved DSS or document-timestamp maintenance under the applicable
historical policies. Its DocMDP maintenance exception does not relax additional
Adobe P1 locks or usage rights. Filling/annotation edits mixed with signing, DSS
or document-timestamp maintenance are refused.

## Explicit usage-rights authority

Clear native UR3 histories with transform version `2.2` require a per-update
`UsageRightsAuthorization`. `UsageRightsAuthority::permits()` receives the actual
`VerifiedRevisionSignature`; its `facts` expose verified algorithms and the bound
signing certificate. The application decides whether this identity may grant
usage rights. Mathematical validity alone grants nothing; the callback does not
implicitly validate PKI paths or revocation.

Pass the authorization among `IncrementalUpdate`'s trailing operation arguments,
for example `new UsageRightsAuthorization($authority, UsageRight::FillField)`.
The nonempty selected operation scope belongs to that update only. Each actual
fill, annotation create/modify/delete or signing operation must satisfy both
signed UR3 rights and this scope, plus all historical DocMDP/FieldMDP/Adobe rules.

Saving is checked separately per actual operation: an unrelated annotation right
cannot make a form fill persistable. ISO 1.7 nonrestrictive `FillIn` requires
signed `FullSave`; restrictive rights, annotation modification rights and actual
signing retain their specified saving rules. Application `FillField` authorizes
a persisted fill when signed policy permits saving: it does not additionally
need application `FullSave` solely because the signed right supplies that saving
condition. PDF 2.0 accepts only explicit restrictive `P=true` in this classifier;
its different `P=false`/absent semantics are not treated as ISO 1.7 rules. Legacy
`UR`, encrypted UR histories, opaque/unverified signatures, failed authority
choices, unclassified maintenance and general-save requests are refused. UR is
deprecated in PDF 2.0.

## Remaining boundaries and evidence

Page-template instantiation, general field creation/deletion/renaming, rich-text
and file-selection filling, arbitrary appearance regeneration, new resource
objects and authenticated reading/classification of external revision chains
remain outside this native scope. Mathematical integrity, modification policy,
certificate trust, revocation and human appearance review are separate. The core
does not depend on the external PDF Reader to prove native changes.

[examples/protected-form-fill.php](../../examples/protected-form-fill.php) signs
DocMDP P2, locks `Reference` with FieldMDP, then fills `Response` with a new
isolated Tx appearance while preserving signed bytes. From a checkout with its
public test signing fixtures, run `php examples/protected-form-fill.php output.pdf`
with the OpenSSL backend available. The public test credential supplies no trust.
qpdf and rendering verify the output. pyHanko classifies this example as `OTHER`
because the changed AP belongs to a widget child without `/T`; its CMS/ByteRange
checks are separate, and no pyHanko DocMDP approval is claimed.

`ProtectedFormFillChangesTest`, `VariableTextAppearanceChangesTest` and
`ProtectedAnnotationChangesTest` cover frozen values, inheritance, widgets,
appearance isolation, ownership and hidden changes. `AuthenticatedUsageRightsTest`
uses real UR3 signatures, explicit authority and subsequent DocMDP P1/P2 or
FieldMDP histories; it checks operation and saving intersections.
`IncrementalSignaturePermissionsTest` retains entry-point, opaque-output and
caller-supplied-history attacks.

`tools/validate_incremental_permissions.py` runs unmodified pyHanko 0.37.0, qpdf
and Poppler. The candidate passes on PHP 8.3/8.4/8.5: 97 native refusals and 148
independently checked PDFs per runtime; 32 native form combinations and 32
independent fills; four P3 annotation chains; 198 pixel checks (144 button, 48
annotation, six shared-appearance attack). UR3 contributes eight signed sources,
three permitted fills and 11 independently verified CMS files; changing a signed
byte is detected. These are CMS/structure/rendering checks, not 148 permission
approvals or an independent usage-rights authority decision.

The unmodified pyHanko policy confirms the 16 `Tx`/`Ch` combinations, but reports
`docmdp_ok=false`/`OTHER` for all 32 filled button outputs (16 native and 16
independent) and 12 P3 annotation operations. Their values, states, CMS and
rendered pixels are checked separately. It accepts two ReadOnly value changes
that the native classifier refuses. Strict pyHanko does not validate hybrid
signatures, so its matrix uses Table/Stream while native hybrid tests remain
separate. Reports retain these differences, with no policy patch, fetching or
implicit PKI trust root.
