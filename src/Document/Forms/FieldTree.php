<?php

declare(strict_types=1);

namespace PDF\Document\Forms;

use PDF\Serialization\SerializationContext;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;

/** One form's mutable hierarchy and inherited document defaults, without global state. */
final class FieldTree
{
    /** @var list<Field> */
    private array $rootFields = [];

    public function __construct(public readonly ?DefaultAppearance $defaultAppearance = null, public readonly ?DictionaryType $defaultResources = null, public readonly ?IntegerType $quadding = null, public readonly BooleanType $needAppearances = new BooleanType(false))
    {
        if ($quadding !== null && !in_array($quadding->value, [0, 1, 2], true)) {
            throw new \InvalidArgumentException('Form quadding is 0, 1 or 2.');
        }
        if ($defaultResources !== null) {
            $fonts = $defaultResources->get('Font');
            $fonts = $fonts instanceof IndirectReference ? $fonts->target() : $fonts;
            if (!$fonts instanceof DictionaryType || count($fonts) === 0) {
                throw new \InvalidArgumentException('Form default resources require at least one Font entry.');
            }
        }
        if ($defaultAppearance !== null && $defaultResources !== null) {
            $defaultAppearance->validateResources($defaultResources);
        }
    }

    public function addRoot(Field $field): self
    {
        if ($field->parent() !== null || $field->tree() !== null) {
            throw new \InvalidArgumentException('A root field has no parent or prior form ownership.');
        }
        $field->claim($this);
        $this->rootFields[] = $field;
        return $this;
    }

    /** @return list<Field> */
    public function roots(): array
    {
        return $this->rootFields;
    }

    public function defaultValue(string $key): ?BasicType
    {
        return match ($key) {
            'DA' => $this->defaultAppearance,
            'DR' => $this->defaultResources,
            'Q' => $this->quadding,
            default => null,
        };
    }

    /** @return list<Field> */
    public function fields(): array
    {
        $pending = $this->rootFields;
        $fields = [];
        $seen = new \SplObjectStorage();
        while ($pending !== []) {
            $field = array_shift($pending);
            if ($seen->offsetExists($field)) {
                throw new \InvalidArgumentException('A field tree contains a cycle or duplicate ownership.');
            }
            $seen->offsetSet($field);
            $fields[] = $field;
            if ($field instanceof NonTerminalField) {
                array_push($pending, ...$field->fields());
            }
        }
        return $fields;
    }

    public function validate(): void
    {
        $representations = [];
        foreach ($this->fields() as $field) {
            if ($field->tree() !== $this || ($field->parent() === null && !in_array($field, $this->rootFields, true))) {
                throw new \InvalidArgumentException('Field ownership must agree with its actual tree.');
            }
            $field->validate();
            if (!$field instanceof TerminalField) {
                continue;
            }
            $name = $field->qualifiedName();
            if (isset($representations[$name])) {
                foreach (['FT', 'V', 'DV'] as $key) {
                    $left = $representations[$name]->effective($key);
                    $right = $field->effective($key);
                    if ($left instanceof IndirectReference || $right instanceof IndirectReference) {
                        $equal = $left instanceof IndirectReference && $right instanceof IndirectReference && $left->target() === $right->target();
                    } else {
                        $equal = $left === null || $right === null ? $left === $right : (new SerializationContext())->serialize($left) === (new SerializationContext())->serialize($right);
                    }
                    if (!$equal) {
                        throw new \InvalidArgumentException('Representations sharing a qualified field name must agree on FT, V and DV.');
                    }
                }
            }
            $representations[$name] = $field;
        }
    }

    private function __clone(): void
    {
    }
}
