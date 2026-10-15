# PDF 1.7 source inventory and executable dictionary relations

English | [Français](README.fr.md)

The retained `PDF32000_2008.pdf` and its exact physical source pages remain the
authority for this edition. `semantic-rules.json` adds **98 executable intrinsic
rules across 33 tables** to the generated definitions, independently of the
PDF 2.0 edition. Rules are explicit immutable `SemanticRule` objects composed
from `DictionaryConstraint` objects. Common-table rules are inherited by derived
definitions and run during `SchemaDictionary::validate()` and serialization.

The checks cover numeric domains, function input/output cardinalities, filter and
parameter positions, cross-reference ranges, calibrated/ICC colour values,
pattern/mesh values, image masks and samples, callout geometry, line leaders and
choice options. They do not infer resource-dependent or application semantics.
The PDF 2.0 `Path` and choice-index corrections do not silently alter PDF 1.7.

The [Adobe 2007 errata review](../adobe-references/errata-review.json) separately
classifies every correction in the supplied 18-page errata. The native writer now
rejects an empty page `/Contents` array; omitting `/Contents` and supplying one
explicit empty stream remain valid. `AnnotationAppearanceTransform` implements
the corrected appearance algorithm `Matrix × A`, including transformed four-corner
bounds, reversed rectangles and noncommutative matrix tests.

`coverage.json` retains its historical baseline audit. Its current development
and source semantic audit records describe later mechanisms; a historical
`schema-only` row must not be read as the current release's total implementation.
Conversely, table inventory and successful serialization do not establish complete
PDF conformance, authenticated revision authorization or viewer rendering.

Regenerate with `python tools/generate_specification.py --check` and run
`tests/SpecificationSemanticsTest.php` for the source-linked relations.
