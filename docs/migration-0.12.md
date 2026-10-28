# Migration to core 0.12

English | [Français](fr/migration-0.12.md)

The writer now validates additional native contracts in the effective document graph, including incremental replacements. Correct previously accepted invalid values before updating a document:

- Page Rotate is an integer multiple of 90; a real 90.0 is invalid. UserUnit must be positive; production boxes contain four numeric coordinates. Tabs accepts R/C/S from PDF 1.5, A/W from PDF 2. Reversed rectangle corners remain valid.
- Outline ownership and sibling links must be reciprocal; signed Count values describe visible descendants when opened. C has three components in 0..1, F uses only its two defined bits, and Dest/A are mutually exclusive.
- Active standard actions require their specific entries and valid types, flags and version. Annotation, OCG, DPart and RichMedia-instance targets are checked against their actual document scope. Document and field script-only trigger locations require JavaScript actions. The writer never executes an action or checks an external resource.
- Named RichMedia assets require matching name-tree/F/UF text names and the native relative-URI restrictions. Instances require a supported Subtype; configuration names are unique as decoded text. Animation/window/position parameters are checked. Unnamed primary assets and unknown animation styles with their specified fallback remain permitted.
- Type 4 `Procedure` bodies are compiled into an immutable `PostScriptCalculatorFunction::program`. Unsupported instructions, standalone procedure operands, excessive brace nesting and proven straight-line stack/type/output violations are rejected. Use `Calculator\Evaluator` and explicit `CalculatorLimits` for evaluation, including input-dependent branches. See the [calculator contracts](../resources/calculator-functions/README.md). Evaluating sample inputs is not a whole-Domain correctness proof.

These checks extend handwritten native mechanisms; generated catalogue counts do not certify full ISO conformity. Keep the original `FileRevision` for authorized native updates; these additions do not grant permission to modify signed or imported files.
