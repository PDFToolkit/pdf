# Native Type 4 calculator contracts

English | [Français](README.fr.md)

`PostScriptCalculatorFunction` compiles explicit `Calculator\Procedure`, `Number` and `Operator` objects into an immutable `program`. Serialization and evaluation use that same snapshot. Nested procedures must immediately form `if` or `ifelse` bodies; standalone PostScript procedure objects and custom instructions are rejected. Source depth is limited to 255 braces, including the outer body. An optional final `CalculatorLimits` constructor argument sets source and evaluation-related budgets.

`Calculator\Evaluator::evaluate($function, ...$inputs)` accepts `NumericType` inputs and returns `NumberArray`. Inputs are clipped to Domain before evaluation and outputs to Range afterwards. Every invocation owns its stack and work counter. Type errors, underflow/overflow, invalid arithmetic domains and wrong output count are explicit exceptions. Number objects preserve their emitted lexical type: use `RealType` when a whole-valued real operand is intended.

`CalculatorLimits` defaults to 100 stack operands, 1,000,000 executed instructions, 100,000 source instructions and 255 nesting levels. Stack capacity cannot be configured below the specification's required minimum of 100. A writer may emit a program requiring a larger evaluation stack; select that larger capacity explicitly when evaluating it.

The writer rejects proven straight-line stack/type/output violations. Conditional and data-dependent stack shapes are deferred to explicit evaluation; different intermediate branch shapes do not prove invalidity. Testing sample inputs does not establish validity over the entire Domain.

All 42 permitted operators are implemented without an external interpreter or I/O. Integers use the PHP build's signed word size (64-bit tested); reals use finite PHP binary64. Logical right shifts fill with zero. Arithmetic overflow may promote integers to real; `idiv` and `cvi` reject results beyond the integer range. Negative half ties in `round` choose the greater integer. See [source and validation coverage](coverage.json).

The independent oracle reads the actual emitted PDF streams with pypdf and evaluates them in Ghostscript. It covers finite cases, not a universal proof. Ghostscript's negative signed right shift differs from the referenced zero-fill rule; direct normative tests cover that boundary separately. Opaque imported/pre-encoded Type 4 streams are outside this typed constructor.
