<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

use PDF\Serialization\SerializationContext;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\FileStructure\CrossReferenceStreamObject;
use PDF\Syntax\FileStructure\ObjectStream;
use PDF\Syntax\FileStructure\RevisionStreamSnapshot;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;

/** Complete frozen-object accounting for classified native changes (ISO2 12.8.2, PDF588–592). */
final class ProtectedRevisionChanges
{
    public readonly FrozenSignatureGraph $prior;
    /** @var array<string, true> */
    private array $changed = [];
    /** @var array<string, true> */
    private array $created = [];
    private int $remaining = 1000000;

    public function __construct(public readonly FrozenSignatureGraph $current)
    {
        $this->prior = new FrozenSignatureGraph($current->revision->previous ?? throw new \LogicException('Classifying changes requires the actual previous revision.'));
    }

    public function change(ObjectIdentifier $identifier): void
    {
        $this->prior->revision->binding($identifier);
        $this->current->revision->binding($identifier);
        $this->changed[(string) $identifier] = true;
    }

    public function create(ObjectIdentifier $identifier): void
    {
        if ($identifier->objectNumber->value < $this->prior->revision->size->integer()) {
            throw new \LogicException('Classified changes allocate fresh objects and never reuse historical free generations.');
        }
        $this->current->revision->binding($identifier);
        $this->created[(string) $identifier] = true;
    }

    public function unchanged(BasicType $reference): bool
    {
        if (!$reference instanceof IndirectReference) {
            return false;
        }
        $record = $this->current->record($reference);
        try {
            $old = $this->prior->revision->binding($record->identifier);
        } catch (\OutOfBoundsException) {
            return false;
        }
        return $this->same($old->snapshot, $record->snapshot);
    }

    /** @param list<string> $except */
    public function sameDictionary(DictionaryType $old, DictionaryType $new, array $except = []): bool
    {
        $keys = [];
        foreach ([$old, $new] as $dictionary) {
            foreach ($dictionary->entries() as $entry) {
                $keys[$entry->key->value] = true;
            }
        }
        foreach (array_keys($keys) as $key) {
            if (!in_array($key, $except, true) && !$this->same($old->get($key), $new->get($key))) {
                return false;
            }
        }
        return true;
    }

    public function same(?BasicType $old, ?BasicType $new): bool
    {
        $this->charge();
        if ($old === null || $new === null) {
            return $old === $new;
        }
        if ($old instanceof IndirectReference || $new instanceof IndirectReference) {
            try {
                return $old instanceof IndirectReference && $new instanceof IndirectReference
                    && (string) $this->prior->record($old)->identifier === (string) $this->current->record($new)->identifier;
            } catch (\OutOfBoundsException $error) {
                throw new \LogicException('Protected changes preserve every referenced object identity.', previous: $error);
            }
        }
        if ($old instanceof RevisionStreamSnapshot || $new instanceof RevisionStreamSnapshot) {
            return $old instanceof RevisionStreamSnapshot && $new instanceof RevisionStreamSnapshot
                && $old->encodedBytes === $new->encodedBytes && $this->sameDictionary($old->dictionary(), $new->dictionary());
        }
        if ($old instanceof StreamObject || $new instanceof StreamObject) {
            throw new \LogicException('Protected comparisons use emitted stream snapshots.');
        }
        if ($old instanceof DictionaryType || $new instanceof DictionaryType) {
            return $old instanceof DictionaryType && $new instanceof DictionaryType && $this->sameDictionary($old, $new);
        }
        if ($old instanceof ArrayType || $new instanceof ArrayType) {
            if (!$old instanceof ArrayType || !$new instanceof ArrayType || count($old->items) !== count($new->items)) {
                return false;
            }
            foreach ($old->items as $index => $item) {
                if (!$this->same($item, $new->items[$index])) {
                    return false;
                }
            }
            return true;
        }
        return $old::class === $new::class && $old->toPdf(new SerializationContext()) === $new->toPdf(new SerializationContext());
    }

    /**
     * Owners are indirect identities. Only form-tree/page-annotation ownership
     * paths count: a second alias under Resources or a private key is not ownership.
     * @param list<string> $owners
     */
    public function assertExclusiveReferences(ObjectIdentifier $identifier, array $owners): void
    {
        foreach ([$this->prior, $this->current] as $graph) {
            foreach ($graph->revision->objects() as $record) {
                $this->references(
                    $record->snapshot instanceof RevisionStreamSnapshot ? $record->snapshot->dictionary() : $record->snapshot,
                    $graph,
                    (string) $identifier,
                    (string) $record->identifier,
                    '',
                    $owners
                );
            }
            $this->references($graph->revision->trailerEntries, $graph, (string) $identifier, 'trailer', '', $owners);
        }
    }

    /** A mutable field-value object is referenced exclusively by that exact field entry. */
    public function assertExclusiveFieldValue(ObjectIdentifier $identifier, ObjectIdentifier $field, string $key): void
    {
        if (!in_array($key, ['V', 'RV'], true)) {
            throw new \InvalidArgumentException('Protected text ownership names V or RV.');
        }
        foreach ([$this->prior, $this->current] as $graph) {
            foreach ($graph->revision->objects() as $record) {
                $this->references(
                    $record->snapshot instanceof RevisionStreamSnapshot ? $record->snapshot->dictionary() : $record->snapshot,
                    $graph,
                    (string) $identifier,
                    (string) $record->identifier,
                    '',
                    [(string) $field],
                    '/' . $key
                );
            }
            $this->references($graph->revision->trailerEntries, $graph, (string) $identifier, 'trailer', '', [(string) $field], '/' . $key);
        }
    }

    /** @param list<string> $owners */
    private function references(BasicType $value, FrozenSignatureGraph $graph, string $target, string $owner, string $path, array $owners, ?string $valuePath = null): void
    {
        $this->charge();
        if ($value instanceof IndirectReference) {
            if ((string) $graph->record($value)->identifier === $target
                && (!in_array($owner, $owners, true) || ($valuePath !== null ? $path !== $valuePath : preg_match('~^(?:(?:/AcroForm)?/(?:Fields|Kids|Annots)(?:/[0-9]+)*|/Parent|(?:/[0-9]+)+)$~D', $path) !== 1))) {
                throw new \LogicException('A changed field, widget or annotation has an unrelated shared reference.');
            }
            return;
        }
        if ($value instanceof ArrayType) {
            foreach ($value->items as $index => $item) {
                $this->references($item, $graph, $target, $owner, $path . '/' . $index, $owners, $valuePath);
            }
        } elseif ($value instanceof DictionaryType) {
            foreach ($value->entries() as $entry) {
                $this->references($entry->value, $graph, $target, $owner, $path . '/' . $entry->key->value, $owners, $valuePath);
            }
        }
    }

    /** New appearances may refer only to unchanged historical resources. */
    public function appearance(?BasicType $entry, ?BasicType $previous = null, bool $variableText = false): void
    {
        if ($entry === null) {
            if ($previous !== null) {
                throw new \LogicException('Protected filling does not remove an existing appearance.');
            }
            return;
        }
        $dictionary = $this->appearanceDictionary($entry, $this->current, true);
        $old = $previous === null ? null : $this->appearanceDictionary($previous, $this->prior, false);
        foreach ($dictionary->entries() as $item) {
            if (!in_array($item->key->value, ['N', 'R', 'D'], true)) {
                throw new \LogicException('Protected appearances contain only N, R and D.');
            }
            if ($variableText && $old !== null && !$old->has($item->key->value)) {
                throw new \LogicException('Variable-text filling cannot add an appearance alternative without a historical program to compare.');
            }
            $this->appearanceEntry($item->value, $old?->get($item->key->value), $variableText);
        }
        if (!$dictionary->has('N')) {
            throw new \LogicException('A classified appearance retains a normal appearance.');
        }
        foreach ($old?->entries() ?? [] as $item) {
            if (!$dictionary->has($item->key->value)) {
                throw new \LogicException('Protected filling preserves existing appearance alternatives.');
            }
        }
    }

    private function appearanceDictionary(BasicType $entry, FrozenSignatureGraph $graph, bool $allowNew): DictionaryType
    {
        if ($entry instanceof IndirectReference) {
            $record = $graph->record($entry);
            if ($allowNew && !$this->unchanged($entry)) {
                $this->create($record->identifier);
            }
            $entry = $record->snapshot;
        }
        return $entry instanceof DictionaryType ? $entry : throw new \LogicException('An appearance dictionary is a plain dictionary.');
    }

    private function appearanceEntry(BasicType $entry, ?BasicType $old, bool $variableText): void
    {
        if ($entry instanceof IndirectReference && $this->current->record($entry)->snapshot instanceof RevisionStreamSnapshot) {
            $record = $this->current->record($entry);
            /** @var RevisionStreamSnapshot $snapshot */
            $snapshot = $record->snapshot;
            $dictionary = $snapshot->dictionary();
            $subtype = $dictionary->get('Subtype');
            if (!$subtype instanceof NameType || $subtype->value !== 'Form') {
                throw new \LogicException('A protected appearance is an actual Form XObject.');
            }
            foreach ($dictionary->entries() as $item) {
                if (!in_array($item->key->value, ['Type', 'Subtype', 'FormType', 'BBox', 'Matrix', 'Resources', 'Length', 'Filter', 'DecodeParms'], true)) {
                    throw new \LogicException('Protected appearance streams have no actions, metadata or unrelated dependencies.');
                }
                if ($item->key->value !== 'Resources') {
                    $this->direct($item->value);
                }
            }
            if (!$this->unchanged($entry)) {
                $this->create($record->identifier);
            }
            $oldSnapshot = $old instanceof IndirectReference ? $this->prior->record($old)->snapshot : null;
            if ($variableText && $old !== null) {
                if (!$oldSnapshot instanceof RevisionStreamSnapshot || !$this->sameDictionary($oldSnapshot->dictionary(), $dictionary, ['Length', 'Filter', 'DecodeParms', 'Resources'])) {
                    throw new \LogicException('Variable-text replacement preserves appearance geometry and structural entries.');
                }
                (new VariableTextAppearanceChanges())->validate($oldSnapshot, $snapshot);
            }
            $this->resources($dictionary->get('Resources'), $oldSnapshot instanceof RevisionStreamSnapshot ? $oldSnapshot->dictionary()->get('Resources') : null);
            return;
        }
        $states = $this->appearanceDictionary($entry, $this->current, true);
        $before = $old === null ? null : $this->appearanceDictionary($old, $this->prior, false);
        foreach ($states->entries() as $state) {
            if ($variableText && $before !== null && !$before->has($state->key->value)) {
                throw new \LogicException('Variable-text filling cannot add an appearance state without a historical program to compare.');
            }
            if (!$state->value instanceof IndirectReference || !$this->current->record($state->value)->snapshot instanceof RevisionStreamSnapshot) {
                throw new \LogicException('Appearance states are indirect Form streams.');
            }
            $this->appearanceEntry($state->value, $before?->get($state->key->value), $variableText);
        }
        foreach ($before?->entries() ?? [] as $state) {
            if (!$states->has($state->key->value)) {
                throw new \LogicException('Protected filling retains existing appearance states.');
            }
        }
    }

    private function resources(?BasicType $entry, ?BasicType $old): void
    {
        if ($entry instanceof IndirectReference) {
            if (!$this->unchanged($entry)) {
                throw new \LogicException('Protected appearances do not mutate or create indirect resource containers.');
            }
            $entry = $this->current->resolve($entry);
        }
        $old = $this->prior->resolve($old);
        if ($entry === null && $old === null) {
            return;
        }
        if (!$entry instanceof DictionaryType || ($old !== null && !$old instanceof DictionaryType)) {
            throw new \LogicException('Appearance resources are explicit dictionaries.');
        }
        foreach ($entry->entries() as $category) {
            if ($category->key->value === 'ProcSet') {
                $this->direct($category->value);
                continue;
            }
            if (!in_array($category->key->value, ['ExtGState', 'ColorSpace', 'Pattern', 'Shading', 'XObject', 'Font', 'Properties'], true)
                || !$category->value instanceof DictionaryType) {
                throw new \LogicException('Protected appearances use recognized direct resource maps.');
            }
            foreach ($category->value->entries() as $resource) {
                if (!$resource->value instanceof IndirectReference || !$this->unchanged($resource->value)) {
                    throw new \LogicException('Appearance resource bindings select unchanged historical objects.');
                }
            }
        }
        foreach ($old instanceof DictionaryType ? $old->entries() : [] as $category) {
            $replacement = $entry->get($category->key->value);
            if ($category->value instanceof DictionaryType && $replacement instanceof DictionaryType) {
                foreach ($category->value->entries() as $resource) {
                    if (!$this->same($resource->value, $replacement->get($resource->key->value))) {
                        throw new \LogicException('Variable-text regeneration preserves every previous resource name binding.');
                    }
                }
            } elseif (!$this->same($category->value, $replacement)) {
                throw new \LogicException('Protected appearances preserve previous resource categories.');
            }
        }
    }

    public function direct(BasicType $value): void
    {
        $this->charge();
        if ($value instanceof IndirectReference || $value instanceof StreamObject) {
            throw new \LogicException('This classified value has no indirect or stream dependencies.');
        }
        foreach ($value instanceof ArrayType ? $value->items : ($value instanceof DictionaryType ? iterator_to_array($value->entries(), false) : []) as $item) {
            $this->direct($item instanceof \PDF\Syntax\Object\DictionaryObject\Entry ? $item->value : $item);
        }
    }

    public function finish(): void
    {
        if ((string) $this->prior->revision->binding($this->prior->revision->root->target())->identifier !== (string) $this->current->revision->binding($this->current->revision->root->target())->identifier
            || !$this->sameDictionary($this->prior->catalog, $this->current->catalog)) {
            throw new \LogicException('Classified form and annotation changes preserve the complete catalog and root identity.');
        }
        if (!$this->sameDictionary($this->prior->revision->trailerEntries, $this->current->revision->trailerEntries, ['ID', 'AuthCode'])) {
            throw new \LogicException('Classified changes preserve trailer ownership and encryption.');
        }
        $oldId = $this->prior->resolve($this->prior->revision->trailerEntries->get('ID'));
        $newId = $this->current->resolve($this->current->revision->trailerEntries->get('ID'));
        if (($oldId === null) !== ($newId === null) || ($oldId instanceof ArrayType && (!$newId instanceof ArrayType || count($oldId->items) !== 2 || count($newId->items) !== 2 || !$this->same($oldId->items[0], $newId->items[0])))) {
            throw new \LogicException('Classified changes preserve the permanent file identifier.');
        }
        $old = [];
        foreach ($this->prior->revision->objects() as $record) {
            $id = (string) $record->identifier;
            $old[$id] = true;
            try {
                $replacement = $this->current->revision->binding($record->identifier);
            } catch (\OutOfBoundsException $error) {
                throw new \LogicException('Classified changes do not retire existing objects.', previous: $error);
            }
            if (!isset($this->changed[$id]) && !$this->same($record->snapshot, $replacement->snapshot)) {
                throw new \LogicException('A protected revision changes an object outside its classified form or annotation operation.');
            }
        }
        foreach ($this->current->revision->objects() as $record) {
            $id = (string) $record->identifier;
            if (isset($old[$id])) {
                continue;
            }
            if ($record->identifier->objectNumber->value < $this->prior->revision->size->integer()) {
                throw new \LogicException('Classified changes cannot reuse historical free numbers.');
            }
            if (isset($this->created[$id])) {
                continue;
            }
            $type = $record->dictionary?->get('Type');
            if (!$record->snapshot instanceof RevisionStreamSnapshot || !($record->value instanceof CrossReferenceStreamObject || $record->value instanceof ObjectStream)
                || !$type instanceof NameType || !in_array($type->value, ['XRef', 'ObjStm'], true)) {
                throw new \LogicException('A protected revision introduces an unclassified or orphan object.');
            }
        }
    }

    private function charge(): void
    {
        if (--$this->remaining < 0) {
            throw new \LengthException('Protected revision comparison exceeds its cumulative node budget.');
        }
    }
}
