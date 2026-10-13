<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;

/** Document-local, immutable class map (table 322 and 14.7.5.2, PDF pages 565/575). */
final class ClassMap extends DictionaryType
{
    /** @var array<string, AttributeClass> */
    private readonly array $classes;

    public function __construct(AttributeClass ...$classes)
    {
        $byName = [];
        foreach ($classes as $class) {
            if (isset($byName[$class->name->value])) {
                throw new \InvalidArgumentException('An attribute class name must be unique.');
            }
            $byName[$class->name->value] = $class;
        }
        $this->classes = $byName;
        parent::__construct(...array_map(static fn (AttributeClass $class): Entry => new Entry($class->name, $class->value()), $classes));
    }

    public function attributeClass(NameType $name): AttributeClass
    {
        return $this->classes[$name->value] ?? throw new \InvalidArgumentException('Undefined attribute class /' . $name->value . '.');
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('Build a class map from explicit attribute-class objects.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('Build a new class map to change its classes.');
    }
}
