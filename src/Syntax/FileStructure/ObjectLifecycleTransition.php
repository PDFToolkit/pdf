<?php

declare(strict_types=1);

namespace PDF\Syntax\FileStructure;

use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;

/** One immutable release/reuse plan over a known generated revision (7.3.9/7.5.4/7.5.7). */
final readonly class ObjectLifecycleTransition
{
    public FreeListState $freeList;
    /** @var list<RevisionObject> */
    public array $liveObjects;
    /** @var list<RetiredObjectGeneration> */
    public array $retiredObjects;
    /** @var list<IndirectObject> */
    public array $reusedObjects;
    /** @var array<int, true> */
    private array $released;
    /** @var array<int, true> */
    private array $reused;

    public function __construct(public FileRevision $previous, ReleaseIndirectObject|ReuseObjectNumber ...$operations)
    {
        $released = [];
        $reused = [];
        $deleted = [];
        $allocated = [];
        $retired = $previous->retiredObjects();
        $knownAliases = new \SplObjectStorage();
        foreach ($previous->objects() as $record) {
            foreach ($record->aliases as $alias) {
                $knownAliases->offsetSet($alias);
            }
        }
        foreach ($retired as $tombstone) {
            foreach ($tombstone->object->aliases as $alias) {
                $knownAliases->offsetSet($alias);
            }
        }
        $spans = [];
        foreach ($previous->spans() as $span) {
            $spans[$span->identifier->objectNumber->value] = $span;
        }
        $seen = [];
        foreach ($operations as $operation) {
            $number = $operation instanceof ReleaseIndirectObject ? $operation->identifier->objectNumber->value : $operation->number->value;
            if (isset($seen[$number])) {
                throw new \LogicException('One number has one lifecycle operation per revision; deletion and reuse occur in separate updates.');
            }
            $seen[$number] = true;
            if ($operation instanceof ReleaseIndirectObject) {
                $record = $previous->binding($operation->identifier);
                $root = $previous->binding($previous->root->target())->identifier;
                $type = $record->dictionary?->get('Type');
                if ((string) $record->identifier === (string) $root
                    || (string) $record->identifier === (string) $previous->pageTreeIdentifier
                    || $record->value === $previous->encryption?->dictionary()
                    || $record->value instanceof CrossReferenceStreamObject
                    || ($type instanceof NameType && in_array($type->value, ['Page', 'Pages', 'Catalog', 'XRef'], true))
                    || $record->dictionary?->has('Linearized')) {
                    throw new \LogicException('Native document, encryption, page-tree and cross-reference structure cannot be released.');
                }
                $deleted[] = $record;
                $retired[] = new RetiredObjectGeneration($record, $spans[$number] ?? null);
                $released[$number] = true;
            } else {
                $entry = $previous->freeList->entry($operation->number);
                if ($entry->generation->value === 65535) {
                    throw new \LogicException('Generation 65535 can never be reused.');
                }
                $value = $operation->value;
                $dictionary = $value instanceof DictionaryType ? $value : ($value instanceof StreamObject ? $value->dictionary() : null);
                $type = $dictionary?->get('Type');
                if ($value instanceof ObjectStream || $value instanceof CrossReferenceStreamObject
                    || ($type instanceof NameType && in_array($type->value, ['ObjStm', 'XRef'], true))) {
                    throw new \LogicException('Reused numbers are ordinary objects, never new object/xref stream containers (7.5.7).');
                }
                if ($knownAliases->offsetExists($value)) {
                    throw new \LogicException('A reused number requires a distinct value identity, never a live or retired alias.');
                }
                $knownAliases->offsetSet($value);
                $allocated[] = new IndirectObject($value, new ObjectIdentifier($operation->number, $entry->generation));
                $reused[$number] = true;
            }
        }
        foreach ($previous->compressedObjects() as $number => $entry) {
            if (isset($released[$entry->objectStreamNumber->value]) && !isset($released[$number])) {
                throw new \LogicException('An object stream cannot be freed while its compressed members remain live.');
            }
        }
        $this->freeList = $previous->freeList->released(...$deleted)->reused(...array_map(fn (IndirectObject $object) => $object->identifier->objectNumber, $allocated));
        $this->liveObjects = array_values(array_filter($previous->objects(), fn (RevisionObject $record): bool => !isset($released[$record->identifier->objectNumber->value])));
        $this->retiredObjects = $retired;
        $this->reusedObjects = $allocated;
        $this->released = $released;
        $this->reused = $reused;
    }

    public function releasedNumber(int $number): bool
    {
        return isset($this->released[$number]);
    }

    public function reusedNumber(int $number): bool
    {
        return isset($this->reused[$number]);
    }
}
