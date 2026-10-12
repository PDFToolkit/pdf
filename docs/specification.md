# Specification model and coverage

The authoritative source for this revision is the `PDF32000_2008.pdf` already
committed to the repository: ISO 32000-1:2008, PDF 1.7. PHP 8.5 does not change
the PDF format version. Implementing PDF 2.0 requires a separate specification
and explicit version-specific definitions.

The source contains 756 PDF pages, 823 outline entries, 370 main numbered tables
and 22 annex tables. The catalogue inventories all 392 tables and all 73 content
stream operator tokens, including the obsolete `F` and inline image delimiters
`BI`, `ID`, and `EI`.

The 263 tables describing dictionary entries, parameters, or attribute entries
produce 263 concrete dictionary classes, 263 immutable table definition classes,
1,399 concrete fixed entry classes and six concrete variable entry classes.
Table identifiers remain in class names to distinguish similarly named
dictionaries and to make each source definition traceable. PDF names are case
sensitive; PHP class names are not. For conflicting names such as `/OP` and `/op`,
the generated class name includes the hexadecimal spelling of its PDF name.

Each entry accepts PDF objects, with a PHP union matching the source's object
types. References remain explicit objects. An indirect reference is checked
against its target type. Stream values require an `IndirectReference`; callers
never pass a direct stream as a dictionary value. Known fixed `Type` names are
validated. Rectangles require four direct numbers. Cross-reference stream entries
and their `Index`/`W` array elements must remain direct.

Each definition is an object composed of `SourceReference`, `Requirement`,
`ObjectTypeConstraint`, and `EntryDefinition` objects. `TableDefinition` composes
common dictionary tables explicitly. `Catalogue` is an immutable collection
constructed by the caller. There is no singleton, shared cache, global service
registry, or runtime JSON lookup. The JSON files are source inventories used by
the reproducible build tools.

Dictionary classes allow incremental construction through immutable `with()`
operations. Serializing them checks unconditional required entries. Conditional
requirements, inherited entries, numeric limits, enumerations other than fixed
`Type` names, relationships between dictionaries, and algorithm-specific rules
are preserved in the source description but are **not all automatically checked**.
Specialized document, page, font, content stream and function objects provide
additional behavior and constraints.

This is an inventory and a low-level object construction model. It is not a
claim that every algorithm or every valid document allowed by the 756-page
standard has an implemented, independently verified semantic model. Encryption,
signature generation, rendering, font subsetting, image codecs, tagged PDF
semantics, multimedia playback and linearization require dedicated implementations
and interoperability tests. Named dictionary classes alone do not implement
those algorithms.

## Rebuilding the source inventory

Use Python with `pypdf` and `pdfplumber` installed:

```sh
python tools/extract_spec.py
python tools/generate_specification.py
python tools/generate_specification.py --check
```

`extract_spec.py` verifies that all main table identifiers from 1 to 370 are
present, extracts ruled table cells, checks the complete 73-token operator list,
and records unresolved extraction problems in `extraction-issues.json`. The
checked-in inventory has zero unresolved extraction problems. It records the
SHA-256 fingerprint of the source PDF and physical PDF page numbers.

`generate_specification.py` emits named classes and immutable definition objects.
Its `--check` mode compares the generated files without modifying them. Only
stale PHP files bearing this generator's marker in its two generated directories
may be removed during regeneration. The Python tools are development tools;
the PHP library has no Python runtime dependency.

The generated source and its inventory must be reviewed together. Extraction
repairs are narrow and documented in the tool: two wrapped key names and two OPI
keys that overflow their printed table cells. Variable names remain variable;
descriptive phrases such as “Other keys” are never emitted as fixed PDF keys.

`corrections.json` records explicit implementation decisions where the source
wording is inconsistent. In Table 15 the ISO 2008 qualifier for `/Prev` says it
shall be indirect, whereas [Adobe PDF Reference 1.7, Table 3.13](https://opensource.adobe.com/dc-acrobat-sdk-docs/pdfstandards/pdfreference1.7old.pdf)
requires a direct integer. This implementation keeps `/Prev` direct so that the
previous cross-reference section can be located before indirect objects are
resolved. The original description and qualifier are retained, and an immutable
`ImplementationCorrection` object exposes the rationale and evidence. This is a
documented implementation decision, not a claim of an official ISO erratum.
Negative qualifiers such as “shall not be an indirect reference” are interpreted
as direct-object constraints without changing their source wording.

## Extending semantic coverage

Add a specialized immutable object for the normative concept, its exact source
clause, and the meaningful constraints on its values and relationships. Compose
existing entry definitions rather than maintaining a second catalogue of names.
Keep parsers, encoders, compression, encryption, signatures, document writing,
and rendering separate from the definition objects, with explicit dependencies.
Use independent readers and validators to test emitted bytes, alongside focused
unit tests for invalid cases. An entry's implementation status should distinguish
its object model, its serialization, its constraints and its associated algorithm.
