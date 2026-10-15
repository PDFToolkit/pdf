<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

use PDF\Serialization\SerializationContext;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\FileStructure\RevisionStreamSnapshot;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringType;

/**
 * ISO 32000-2 12.8.2.2/12.8.5.3, physical pages 589/606: narrow native signing and maintenance deltas.
 * Prove the complete frozen delta contains only that maintenance before applying
 * this rule. FieldMDP restrictions are assessed separately and remain binding.
 */
final class SignatureRevisionDelta
{
    /** @var array<string, true> */
    private array $allowed = [];
    /** @var array<string, true> */
    private array $mutableExisting = [];

    public function validate(FrozenSignatureGraph $current, ?FrozenSignatureField $field, ?PendingSignatureDictionary $pending, bool $allowDss = true): void
    {
        $previous = $current->revision->previous;
        if ($previous === null) {
            throw new \LogicException('A DocMDP maintenance exception requires an actual incremental history.');
        }
        $prior = new FrozenSignatureGraph($previous);
        $this->allowed = [];
        $this->mutableExisting = [];
        if ($pending !== null && $field !== null) {
            $signature = $current->revision->binding($pending);
            try {
                $previousReservation = $previous->binding($signature->identifier)->value instanceof PendingSignatureDictionary;
            } catch (\OutOfBoundsException) {
                $previousReservation = false;
            }
            $frozen = $signature->dictionary;
            if ($signature->value !== $pending || !$frozen?->get('Type') instanceof NameType || $frozen->get('Type')->value !== ($pending instanceof PendingDocumentTimestamp ? 'DocTimeStamp' : 'Sig')
                || !$frozen->get('SubFilter') instanceof NameType || $frozen->get('SubFilter')->value !== $pending->format->value
                || (!$previousReservation && $frozen->has('Reference')) || count($current->bindings($signature->identifier)) !== 1) {
                throw new \LogicException('Protected signing selects one actual native signature reservation without additional transforms.');
            }
            foreach ($frozen->entries() as $entry) {
                if (!$previousReservation && (!in_array($entry->key->value, ['Contents', 'ByteRange', 'Type', 'Filter', 'SubFilter', 'Cert', 'Name', 'M', 'Location', 'Reason', 'ContactInfo'], true) || $this->hasReference($entry->value))) {
                    throw new \LogicException('Protected native signing permits no new policy, action or arbitrary signature dependencies.');
                }
            }
            $this->allow($current->revision->reference($pending), $current);
            $value = $field->effective('V');
            if (!$value instanceof IndirectReference || (string) $current->record($value)->identifier !== (string) $signature->identifier) {
                throw new \LogicException('The signature belongs to the selected frozen signature field.');
            }
            $type = $current->resolve($field->dictionary->get('FT'));
            if (!$type instanceof NameType || $type->value !== 'Sig' || !$field->dictionary->get('T') instanceof StringType) {
                throw new \LogicException('The native signature field has its own explicit signature type and name.');
            }
            $fieldId = (string) $field->identifier;
            $this->allowed[$fieldId] = true;
            $oldField = null;
            foreach ($prior->fields() as $candidate) {
                if ((string) $candidate->identifier === $fieldId) {
                    $oldField = $candidate;
                    $this->mutableExisting[$fieldId] = true;
                    $oldValue = $candidate->effective('V');
                    $reservedValue = $oldValue instanceof IndirectReference && $previousReservation && (string) $prior->record($oldValue)->identifier === (string) $signature->identifier;
                    if ((!$reservedValue && $prior->resolve($oldValue) !== null) || !$this->sameDictionary($candidate->dictionary, $field->dictionary, $prior, $current, ['V'])) {
                        throw new \LogicException('An existing signature field may only acquire its previously absent or reserved unsigned value.');
                    }
                }
            }
            if ($oldField === null) {
                $this->knownKeys($field->dictionary, ['FT', 'T', 'V', 'Ff']);
            }
        } elseif ($pending !== null || $field !== null) {
            throw new \LogicException('Protected signing selects one actual field and reservation together.');
        } elseif ($current->catalog->get('DSS') === null || $this->same($prior->catalog->get('DSS'), $current->catalog->get('DSS'), $prior, $current)) {
            throw new \LogicException('A protected revision without signing must perform explicit DSS maintenance.');
        }
        $catalogId = (string) $current->revision->binding($current->revision->root->target())->identifier;
        if ($catalogId !== (string) $prior->revision->binding($prior->revision->root->target())->identifier
            || !$this->sameDictionary($prior->catalog, $current->catalog, $prior, $current, ($pending === null ? ($allowDss ? ['DSS'] : []) : ($allowDss ? ['AcroForm', 'DSS'] : ['AcroForm'])))) {
            throw new \LogicException('Protected signing and maintenance preserves every other catalog entry and the root identity.');
        }
        $this->allowed[$catalogId] = true;
        $this->mutableExisting[$catalogId] = true;
        if ($pending !== null && $field !== null) {
            $this->form($prior, $current, (string) $field->identifier, $oldField !== null);
        }
        if ($allowDss) {
            $this->dss($current);
        }
        if (!$this->sameDictionary($prior->revision->trailerEntries, $current->revision->trailerEntries, $prior, $current, ['AuthCode', 'ID'])) {
            throw new \LogicException('Protected signing and maintenance preserves trailer ownership and encryption.');
        }
        $oldId = $prior->resolve($prior->revision->trailerEntries->get('ID'));
        $newId = $current->resolve($current->revision->trailerEntries->get('ID'));
        if (($oldId === null) !== ($newId === null) || ($oldId instanceof ArrayType && (!$newId instanceof ArrayType || count($oldId->items) !== 2 || count($newId->items) !== 2
            || !$this->same($oldId->items[0], $newId->items[0], $prior, $current)))) {
            throw new \LogicException('Protected signing and maintenance preserves the permanent file identifier.');
        }
        $oldObjects = [];
        foreach ($previous->objects() as $record) {
            $id = (string) $record->identifier;
            $oldObjects[$id] = true;
            try {
                $replacement = $current->revision->binding($record->identifier);
            } catch (\OutOfBoundsException $error) {
                throw new \LogicException('Protected signing and maintenance cannot retire existing objects.', previous: $error);
            }
            if (!isset($this->mutableExisting[$id]) && !$this->same($record->snapshot, $replacement->snapshot, $prior, $current)) {
                throw new \LogicException('Protected signing and maintenance cannot change pages, content, widgets or other existing objects.');
            }
        }
        foreach ($current->revision->objects() as $record) {
            $id = (string) $record->identifier;
            if (!isset($oldObjects[$id]) && $record->identifier->objectNumber->value < $previous->size->integer()) {
                throw new \LogicException('Protected maintenance allocates fresh numbers instead of reusing historical free generations.');
            }
            if (isset($oldObjects[$id]) || isset($this->allowed[$id])) {
                continue;
            }
            $type = $record->dictionary?->get('Type');
            if (!$record->snapshot instanceof RevisionStreamSnapshot || (!$record->value instanceof \PDF\Syntax\FileStructure\CrossReferenceStreamObject && !$record->value instanceof \PDF\Syntax\FileStructure\ObjectStream) || !$type instanceof NameType || !in_array($type->value, ['XRef', 'ObjStm'], true)) {
                throw new \LogicException('Protected signing and maintenance introduces only its explicit field, signature, DSS and structural objects.');
            }
        }
    }

    private function form(FrozenSignatureGraph $prior, FrozenSignatureGraph $current, string $fieldId, bool $existing): void
    {
        $form = $current->form ?? throw new \LogicException('A protected signature belongs to the actual AcroForm.');
        if (!$this->sameDictionary($prior->form ?? new DictionaryType(), $form, $prior, $current, ['Fields', 'SigFlags'])) {
            throw new \LogicException('Protected signing and maintenance preserves other AcroForm settings.');
        }
        $this->allow($current->catalog->get('AcroForm'), $current);
        $this->mutableOwner($prior->catalog->get('AcroForm'), $current->catalog->get('AcroForm'), $prior, $current);
        $old = $prior->resolve($prior->form?->get('Fields'));
        $new = $current->resolve($form->get('Fields'));
        $before = $old instanceof ArrayType ? $old->items : [];
        if (!$new instanceof ArrayType || count($new->items) !== count($before) + ($existing ? 0 : 1)) {
            throw new \LogicException('Protected signing and maintenance only appends its one new field.');
        }
        foreach ($before as $index => $reference) {
            if (!$this->same($reference, $new->items[$index], $prior, $current)) {
                throw new \LogicException('Protected signing and maintenance preserves every existing field reference and its order.');
            }
        }
        if (!$existing) {
            $last = $new->items[count($before)];
            if (!$last instanceof IndirectReference || (string) $current->record($last)->identifier !== $fieldId) {
                throw new \LogicException('Only the actual signature field is appended.');
            }
        }
        $this->allow($form->get('Fields'), $current);
        $this->mutableOwner($prior->form?->get('Fields'), $form->get('Fields'), $prior, $current);
        $oldFlags = $prior->resolve($prior->form?->get('SigFlags')) ?? new IntegerType(0);
        $newFlags = $current->resolve($form->get('SigFlags')) ?? new IntegerType(0);
        if (!$oldFlags instanceof IntegerType || !$newFlags instanceof IntegerType || ($newFlags->integer() & ~3) !== ($oldFlags->integer() & ~3)
            || ($newFlags->integer() | $oldFlags->integer()) !== $newFlags->integer()) {
            throw new \LogicException('Protected signing and maintenance only enables signature-present and append-only flags.');
        }
    }

    private function dss(FrozenSignatureGraph $graph): void
    {
        $entry = $graph->catalog->get('DSS');
        $store = $graph->resolve($entry);
        if ($store === null) {
            return;
        }
        if (!$store instanceof DictionaryType) {
            throw new \LogicException('Maintenance DSS is a dictionary.');
        }
        $this->allow($entry, $graph);
        $this->knownKeys($store, ['Type', 'Certs', 'CRLs', 'OCSPs', 'VRI']);
        foreach (['Certs', 'CRLs', 'OCSPs'] as $key) {
            $this->evidence($store->get($key), $graph);
        }
        $vri = $graph->resolve($store->get('VRI'));
        $this->allow($store->get('VRI'), $graph);
        if ($vri instanceof DictionaryType) {
            foreach ($vri->entries() as $item) {
                $information = $graph->resolve($item->value);
                if (!$information instanceof DictionaryType) {
                    throw new \LogicException('Maintenance VRI entries are dictionaries.');
                }
                $this->allow($item->value, $graph);
                $this->knownKeys($information, ['Type', 'Cert', 'CRL', 'OCSP', 'TU', 'TS']);
                foreach (['Cert', 'CRL', 'OCSP'] as $key) {
                    $this->evidence($information->get($key), $graph);
                }
                $this->stream($information->get('TS'), $graph);
            }
        }
    }

    private function evidence(?BasicType $entry, FrozenSignatureGraph $graph): void
    {
        $array = $graph->resolve($entry);
        $this->allow($entry, $graph);
        if ($array instanceof ArrayType) {
            foreach ($array->items as $reference) {
                $this->stream($reference, $graph);
            }
        } elseif ($array !== null) {
            throw new \LogicException('Maintenance validation evidence is an array.');
        }
    }

    private function stream(?BasicType $entry, FrozenSignatureGraph $graph): void
    {
        if ($entry === null) {
            return;
        }
        if (!$entry instanceof IndirectReference || !$graph->record($entry)->snapshot instanceof RevisionStreamSnapshot) {
            throw new \LogicException('Maintenance validation evidence is an actual emitted indirect stream.');
        }
        // Evidence streams cannot smuggle arbitrary PDF references through their dictionaries.
        $dictionary = $graph->record($entry)->dictionary ?? throw new \LogicException('Evidence has a stream dictionary.');
        $this->knownKeys($dictionary, ['Length', 'Filter', 'DecodeParms', 'Subtype']);
        foreach ($dictionary->entries() as $value) {
            if ($this->hasReference($value->value)) {
                throw new \LogicException('Maintenance evidence stream parameters are direct values.');
            }
        }
        $this->allow($entry, $graph);
    }

    /** @param list<string> $keys */
    private function knownKeys(DictionaryType $dictionary, array $keys): void
    {
        foreach ($dictionary->entries() as $entry) {
            if (!in_array($entry->key->value, $keys, true)) {
                throw new \LogicException('Protected revisions permit only recognized operation entries.');
            }
        }
    }

    private function hasReference(BasicType $value): bool
    {
        if ($value instanceof IndirectReference) {
            return true;
        }
        foreach ($value instanceof ArrayType ? $value->items : ($value instanceof DictionaryType ? iterator_to_array($value->entries(), false) : []) as $item) {
            if ($this->hasReference($item instanceof \PDF\Syntax\Object\DictionaryObject\Entry ? $item->value : $item)) {
                return true;
            }
        }
        return false;
    }

    private function allow(?BasicType $value, FrozenSignatureGraph $graph): void
    {
        if ($value instanceof IndirectReference) {
            $this->allowed[(string) $graph->record($value)->identifier] = true;
        }
    }

    private function mutableOwner(?BasicType $old, ?BasicType $new, FrozenSignatureGraph $prior, FrozenSignatureGraph $current): void
    {
        if ($old instanceof IndirectReference && $new instanceof IndirectReference
            && (string) $prior->record($old)->identifier === (string) $current->record($new)->identifier) {
            $this->mutableExisting[(string) $prior->record($old)->identifier] = true;
        }
    }

    /** @param list<string> $except */
    private function sameDictionary(DictionaryType $old, DictionaryType $new, FrozenSignatureGraph $prior, FrozenSignatureGraph $current, array $except = []): bool
    {
        $keys = [];
        foreach ([$old, $new] as $dictionary) {
            foreach ($dictionary->entries() as $entry) {
                $keys[$entry->key->value] = true;
            }
        }
        foreach (array_keys($keys) as $key) {
            if (!in_array($key, $except, true) && !$this->same($old->get($key), $new->get($key), $prior, $current)) {
                return false;
            }
        }
        return true;
    }

    private function same(?BasicType $old, ?BasicType $new, FrozenSignatureGraph $prior, FrozenSignatureGraph $current): bool
    {
        if ($old === null || $new === null) {
            return $old === $new;
        }
        if ($old instanceof IndirectReference || $new instanceof IndirectReference) {
            return $old instanceof IndirectReference && $new instanceof IndirectReference && (string) $prior->record($old)->identifier === (string) $current->record($new)->identifier;
        }
        if ($old instanceof RevisionStreamSnapshot || $new instanceof RevisionStreamSnapshot) {
            return $old instanceof RevisionStreamSnapshot && $new instanceof RevisionStreamSnapshot && $old->encodedBytes === $new->encodedBytes
                && $this->sameDictionary($old->dictionary(), $new->dictionary(), $prior, $current);
        }
        if ($old instanceof StreamObject || $new instanceof StreamObject) {
            throw new \LogicException('Maintenance comparison requires the actual emitted stream snapshots.');
        }
        if ($old instanceof DictionaryType || $new instanceof DictionaryType) {
            return $old instanceof DictionaryType && $new instanceof DictionaryType && $this->sameDictionary($old, $new, $prior, $current);
        }
        if ($old instanceof ArrayType || $new instanceof ArrayType) {
            if (!$old instanceof ArrayType || !$new instanceof ArrayType || count($old->items) !== count($new->items)) {
                return false;
            }
            foreach ($old->items as $index => $item) {
                if (!$this->same($item, $new->items[$index], $prior, $current)) {
                    return false;
                }
            }
            return true;
        }
        return $old::class === $new::class && $old->toPdf(new SerializationContext()) === $new->toPdf(new SerializationContext());
    }
}
