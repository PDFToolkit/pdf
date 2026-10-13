<?php

declare(strict_types=1);

namespace PDF\Syntax\FileStructure;

use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringType;

/** An effective indirect value and the PHP identities that refer to its frozen revision. */
final readonly class RevisionObject
{
    /** @var list<BasicType> */
    public array $aliases;
    public ?DictionaryType $dictionary;
    public BasicType $snapshot;
    public string $objectKind;

    public function __construct(public ObjectIdentifier $identifier, public BasicType $value, BasicType|SerializedRevisionValue ...$aliases)
    {
        if ($value instanceof IndirectReference || $identifier->generationNumber->value === 65535) {
            throw new \InvalidArgumentException('A revision object requires a live indirect value.');
        }
        $identities = [];
        $serialized = null;
        foreach ([$value, ...$aliases] as $alias) {
            if ($alias instanceof SerializedRevisionValue) {
                if ($serialized !== null) {
                    throw new \InvalidArgumentException('A revision value has one explicit serialized snapshot.');
                }
                $serialized = $alias;
                continue;
            }
            if ($alias instanceof IndirectReference) {
                throw new \InvalidArgumentException('A revision alias must be an indirect value.');
            }
            $identities[spl_object_id($alias)] = $alias;
        }
        $this->aliases = array_values($identities);
        $actual = $serialized === null ? $value : $serialized->value;
        $dictionary = $actual instanceof DictionaryType ? $actual : ($actual instanceof StreamObject ? $actual->dictionary() : null);
        $capture = new RevisionValueSnapshot();
        $frozen = $dictionary === null ? null : $capture->capture($dictionary);
        $this->dictionary = $frozen instanceof DictionaryType ? $frozen : null;
        $this->snapshot = $actual instanceof StreamObject ? $actual : $capture->capture($actual);
        $this->objectKind = match (true) {
            $value instanceof StreamObject => 'stream',
            $value instanceof DictionaryType => 'dictionary',
            $value instanceof ArrayType => 'array',
            $value instanceof IntegerType => 'integer',
            $value instanceof NumericType => 'number',
            $value instanceof StringType => 'string',
            $value instanceof NameType => 'name',
            $value instanceof BooleanType => 'boolean',
            $value instanceof NullType => 'null',
            default => $value::class,
        };
    }

    #[\NoDiscard]
    public function replacedBy(BasicType $value, ?SerializedRevisionValue $serialized = null): self
    {
        $replacement = new self($this->identifier, $value, ...[...$this->aliases, ...($serialized === null ? [] : [$serialized])]);
        if ($replacement->objectKind !== $this->objectKind && !($this->objectKind === 'number' && $replacement->objectKind === 'integer')) {
            throw new \LogicException('A replacement must preserve the PDF object type of existing references.');
        }
        $type = $this->dictionary?->get('Type');
        $newType = $replacement->dictionary?->get('Type');
        if ($type instanceof NameType && (!$newType instanceof NameType || $newType->value !== $type->value)) {
            throw new \LogicException('A replacement must preserve the historical dictionary Type.');
        }
        return $replacement;
    }
}
