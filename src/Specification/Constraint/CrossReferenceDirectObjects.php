<?php

declare(strict_types=1);

namespace PDF\Specification\Constraint;

use PDF\Specification\DictionaryConstraint;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;

/** EC3 issues 246 and 403: recursively direct stream entries, no external file/metadata/AF. */
final readonly class CrossReferenceDirectObjects implements DictionaryConstraint
{
    public function validate(DictionaryType $dictionary): void
    {
        $values = new DictionaryValues();
        foreach (['F', 'Metadata', 'AF'] as $key) {
            if ($values->value($dictionary, $key) !== null) {
                throw new \InvalidArgumentException('A cross-reference stream forbids /' . $key . '.');
            }
        }
        foreach (['Length', 'Filter', 'DecodeParms', 'FFilter', 'FDecodeParms', 'DL'] as $key) {
            $value = $dictionary->get($key);
            if ($value !== null) {
                $this->direct($value, $key);
            }
        }
    }

    private function direct(BasicType $value, string $key): void
    {
        if ($value instanceof IndirectReference) {
            throw new \InvalidArgumentException('Cross-reference stream /' . $key . ' and its contained values shall be direct.');
        }
        if ($value instanceof ArrayType) {
            foreach ($value->items as $item) {
                $this->direct($item, $key);
            }
        }
        if ($value instanceof DictionaryType) {
            foreach ($value->entries() as $entry) {
                $this->direct($entry->value, $key);
            }
        }
    }
}
