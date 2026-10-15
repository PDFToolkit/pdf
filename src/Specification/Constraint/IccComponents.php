<?php

declare(strict_types=1);

namespace PDF\Specification\Constraint;

use PDF\Specification\DictionaryConstraint;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;

final readonly class IccComponents implements DictionaryConstraint
{
    public function validate(DictionaryType $dictionary): void
    {
        $values = new DictionaryValues();
        $components = $values->value($dictionary, 'N');
        $range = $values->numbers($dictionary, 'Range');
        if ($components instanceof IntegerType && $range !== null && count($range) !== 2 * $components->integer()) {
            throw new \InvalidArgumentException('ICC /Range shall have two numbers per component.');
        }
        $alternate = $values->value($dictionary, 'Alternate');
        if ($alternate instanceof NameType) {
            $count = match ($alternate->value) {
                'DeviceGray' => 1, 'DeviceRGB' => 3, 'DeviceCMYK' => 4,
                'Pattern' => throw new \InvalidArgumentException('An ICC alternate cannot be Pattern.'),
                default => null,
            };
            if ($count !== null && $components instanceof IntegerType && $components->integer() !== $count) {
                throw new \InvalidArgumentException('ICC /Alternate component count disagrees with /N.');
            }
        }
    }
}
