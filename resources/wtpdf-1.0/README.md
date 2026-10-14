# WTPDF requirement review

[English](README.md) · [Français](README.fr.md)

The [requirement inventory](requirements.json) traces each independently
assessable obligation, conditional branch or permission to its clause and
physical page in the retained `Well-Tagged-PDF-WTPDF-1.0.pdf` (version 1.0.0,
February 2024). All 57 pages and 173 link annotations were reviewed; the
annotations contain no normative corrections. The original is licensed
CC-BY-4.0 by the PDF Association and retains its bytes and SHA-256 in the
[bundle source manifest](../iso32000-2-bundle/sources.json).

Each row records its precise scope, justification, implementation or boundary,
and evidence files. Its status is one of:

- `executed`: the named native model or check executes the stated aspect;
- `human`: author intent or semantic adequacy requires human evidence;
- `external`: independent format, program, trust or accessibility validation;
- `absent`: a mechanically assessable aspect has no implemented check.

Colon ordinals distinguish separate aspects in one clause; they do not invent
printed paragraph numbers. Some paragraphs have both an executed mechanical
condition and a human or external obligation, recorded separately. Informative
appendices remain identified as examples. Physical PDF pages differ from the
printed folios by three pages.

`coverage.json` is a concise mechanism summary. It does not replace the
requirement inventory. Neither document reports a completeness percentage or
turns a declaration, successful subset or absence of detected failures into a
WTPDF/PDF/UA certificate. Traversal budgets and incomplete external font/content
evidence remain explicit in the audit API and findings.

After installing the pinned Python validation dependencies, check source
identity, normative leaf clauses and every declared evidence path with:

```sh
python tools/check_wtpdf_requirements.py
python -m unittest discover -s tests -p test_wtpdf_requirements.py
```

The negative regressions reject an omitted clause, an altered source hash and
missing or escaping evidence paths. Clause coverage does not prove that every
paragraph obligation in that clause is executed; inspect each aspect's status.
