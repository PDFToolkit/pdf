<?php

declare(strict_types=1);

namespace PDF\Specification\Constraint;

use PDF\Specification\DictionaryConstraint;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NullType;

/** Stream filters and their positional parameter dictionaries, including null slots. */
final readonly class FilterParameters implements DictionaryConstraint
{
    public function __construct(public string $filterKey = 'Filter', public string $parametersKey = 'DecodeParms')
    {
    }

    public function validate(DictionaryType $dictionary): void
    {
        $values = new DictionaryValues();
        $filters = $values->names($dictionary, $this->filterKey);
        $parameters = $values->value($dictionary, $this->parametersKey);
        if ($parameters === null) {
            return;
        }
        if ($filters === []) {
            throw new \InvalidArgumentException('/' . $this->parametersKey . ' requires a filter.');
        }
        if ($parameters instanceof DictionaryType && count($filters) === 1) {
            return;
        }
        if (!$parameters instanceof ArrayType || count($parameters) !== count($filters)) {
            throw new \InvalidArgumentException('/' . $this->parametersKey . ' must provide exactly one positional slot per filter.');
        }
        foreach ($parameters->items as $parameter) {
            $parameter = $parameter instanceof IndirectReference ? $parameter->target() : $parameter;
            if (!$parameter instanceof DictionaryType && !$parameter instanceof NullType) {
                throw new \InvalidArgumentException('A filter parameter slot must be a dictionary or null.');
            }
        }
    }
}
