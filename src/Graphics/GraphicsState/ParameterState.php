<?php

declare(strict_types=1);

namespace PDF\Graphics\GraphicsState;

use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;

/** Nonserializable immutable state snapshot; Default resolves against the explicit page initial state. */
final readonly class ParameterState
{
    private DictionaryType $values;

    public function __construct(Entry ...$values)
    {
        $this->values = new DictionaryType(...$values);
    }

    public function get(string $key): ?BasicType
    {
        return $this->values->get($key);
    }

    public function apply(GraphicsStateParameters $parameters, self $pageInitial): self
    {
        $values = $this->values;
        foreach ($parameters->effectiveEntries() as $entry) {
            $key = $entry->key->value;
            $canonical = in_array($key, ['BG2', 'UCR2', 'TR2'], true) ? substr($key, 0, -1) : $key;
            $value = $entry->value;
            if (in_array($key, ['BG2', 'UCR2', 'TR2', 'HT'], true) && $value instanceof NameType && $value->value === 'Default') {
                $value = $pageInitial->get($canonical);
                if ($value === null) {
                    throw new \InvalidArgumentException('Default resolution requires the explicit page-initial /' . $canonical . ' value.');
                }
            }
            $values = $values->with(new Entry(new NameType($canonical), $value));
        }
        return new self(...iterator_to_array($values->entries(), false));
    }
}
