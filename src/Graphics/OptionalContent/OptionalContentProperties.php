<?php

declare(strict_types=1);

namespace PDF\Graphics\OptionalContent;

use PDF\Document\CatalogComponent;
use PDF\Specification\Definition\OptionalContentPropertiesDictionaryTable100Definition;
use PDF\Specification\SchemaDictionary;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;

/** Complete document group scope and configuration relationships (8.11.4.2). */
final class OptionalContentProperties extends SchemaDictionary implements CatalogComponent, \PDF\Document\VersionedComponent
{
    public function validateVersion(\PDF\Syntax\FileStructure\EffectivePdfVersion $version): void
    {
        $version->require(\PDF\Syntax\FileStructure\PdfVersion::PDF15, 'Optional content properties');
    }

    public const string TABLE = '100';
    /** @var list<Configuration> */
    public readonly array $alternatives;

    public function __construct(public readonly GroupCollection $groups, public readonly Configuration $default = new Configuration(), Configuration ...$alternatives)
    {
        $default->validateDefault();
        foreach ([$default, ...$alternatives] as $configuration) {
            foreach ($configuration->referencedGroups()->groups as $group) {
                if (!$groups->contains($group)) {
                    throw new \InvalidArgumentException('All configuration/usage references must belong to this document group collection.');
                }
            }
        }
        $this->alternatives = array_values($alternatives);
        parent::__construct(new OptionalContentPropertiesDictionaryTable100Definition(), new Entry(new NameType('OCGs'), $groups), new Entry(new NameType('D'), $default), new Entry(new NameType('Configs'), new ArrayType(...$this->alternatives)));
    }

    public function states(): GroupStates
    {
        return new GroupStates($this->groups, $this->default);
    }

    public function catalogEntry(): Entry
    {
        return new Entry(new NameType('OCProperties'), $this);
    }

    public function validateCatalog(DictionaryType $catalog): void
    {
        $root = $catalog->get('OCProperties');
        if (($root instanceof IndirectReference ? $root->target() : $root) !== $this) {
            throw new \InvalidArgumentException('Optional content properties must validate the actual catalog OCProperties root.');
        }
        /** @var list<array{BasicType, bool}> $queue */
        $queue = [[$catalog, false]];
        $seen = [];
        while ($queue !== []) {
            [$value, $indirect] = array_pop($queue);
            if ($value instanceof IndirectReference) {
                $queue[] = [$value->target(), true];
                continue;
            }
            if ($value instanceof DictionaryType) {
                $type = $value->get('Type');
                if ($type instanceof NameType && $type->value === 'OCG' && (!$indirect || !$value instanceof OptionalContentGroup || !$this->groups->contains($value))) {
                    throw new \InvalidArgumentException('Every reached OCG must be an indirect member of the explicit document group collection.');
                }
            }
            $id = spl_object_id($value);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            if (count($seen) > 1000000) {
                throw new \LengthException('Optional-content document graph exceeds its one-million-object construction limit.');
            }
            if ($value instanceof DictionaryType) {
                foreach ($value->entries() as $entry) {
                    $queue[] = [$entry->value, false];
                }
            } elseif ($value instanceof ArrayType) {
                foreach ($value->items as $item) {
                    $queue[] = [$item, false];
                }
            } elseif ($value instanceof StreamObject) {
                $queue[] = [$value->dictionary(), false];
                if ($value->data instanceof ContentStreams) {
                    foreach ($value->data->operators as $operator) {
                        foreach ($operator->operands as $operand) {
                            $queue[] = [$operand, false];
                        }
                    }
                }
            }
        }
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('Document OCG scope and configurations are constructor-controlled.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('Document OCG scope and configurations are constructor-controlled.');
    }
}
