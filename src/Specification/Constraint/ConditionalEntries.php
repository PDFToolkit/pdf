<?php

declare(strict_types=1);

namespace PDF\Specification\Constraint;

use PDF\Specification\DictionaryConstraint;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\DictionaryType;

/** A source condition on presence or on the effective scalar object value. */
final readonly class ConditionalEntries implements DictionaryConstraint
{
    /**
     * @param list<string> $required
     * @param list<string> $prohibited
     */
    public function __construct(public string $conditionKey, public array $required = [], public array $prohibited = [], public ?BasicType $equals = null, public bool $whenAbsent = false)
    {
        if ($conditionKey === '' || array_intersect($required, $prohibited) !== []) {
            throw new \InvalidArgumentException('Invalid conditional entry relation.');
        }
    }

    public function validate(DictionaryType $dictionary): void
    {
        $values = new DictionaryValues();
        $condition = $values->value($dictionary, $this->conditionKey);
        $matches = $this->equals === null ? $condition !== null : $condition == $this->equals;
        if ($this->whenAbsent) {
            $matches = !$matches;
        }
        if (!$matches) {
            return;
        }
        foreach ($this->required as $key) {
            if ($values->value($dictionary, $key) === null) {
                throw new \InvalidArgumentException('/' . $key . ' is required by /' . $this->conditionKey . '.');
            }
        }
        foreach ($this->prohibited as $key) {
            if ($values->value($dictionary, $key) !== null) {
                throw new \InvalidArgumentException('/' . $key . ' is prohibited by /' . $this->conditionKey . '.');
            }
        }
    }
}
