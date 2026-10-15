<?php

declare(strict_types=1);

namespace PDF\Specification;

use PDF\Syntax\Object\DictionaryType;

/** Immutable rule identity and source; no source parsing or registry at runtime. */
final readonly class SemanticRule implements DictionaryConstraint
{
    public function __construct(public string $id, public SourceReference $source, public DictionaryConstraint $constraint)
    {
        if ($id === '') {
            throw new \InvalidArgumentException('A semantic rule requires an identifier.');
        }
    }

    public function validate(DictionaryType $dictionary): void
    {
        try {
            $this->constraint->validate($dictionary);
        } catch (\InvalidArgumentException $exception) {
            throw new \InvalidArgumentException($this->source->standard . ', table ' . $this->source->table . ', physical PDF page ' . $this->source->pdfPage . ' [' . $this->id . ']: ' . $exception->getMessage(), previous: $exception);
        }
    }
}
