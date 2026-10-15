<?php

declare(strict_types=1);

namespace PDF\Specification\Constraint;

use PDF\Specification\DictionaryConstraint;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;

final readonly class AnnotationPath implements DictionaryConstraint
{
    public function validate(DictionaryType $dictionary): void
    {
        $values = new DictionaryValues();
        $path = $values->value($dictionary, 'Path');
        if ($path === null) {
            if ($values->value($dictionary, 'Vertices') === null) {
                throw new \InvalidArgumentException('A polygon or polyline requires /Vertices or /Path.');
            }
            (new NumberArray('Vertices', minimumLength: 4, multipleOf: 2))->validate($dictionary);
            return;
        }
        if ($values->value($dictionary, 'Vertices') !== null) {
            throw new \InvalidArgumentException('/Path forbids /Vertices.');
        }
        if (!$path instanceof ArrayType || count($path) < 1) {
            throw new \InvalidArgumentException('/Path requires a nonempty sequence of path operand arrays.');
        }
        foreach ($path->items as $index => $operands) {
            $operands = $operands instanceof IndirectReference ? $operands->target() : $operands;
            $part = new DictionaryType(new Entry(new NameType('Operands'), $operands));
            (new NumberArray('Operands', lengths: $index === 0 ? [2] : [2, 6]))->validate($part);
        }
    }
}
