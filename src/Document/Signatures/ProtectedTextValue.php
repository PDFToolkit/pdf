<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\RevisionStreamSnapshot;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\StringObject\LiteralString;
use PDF\Syntax\Object\StringType;

/** Text values are inspected from emitted bytes and accounted for by their exact V/RV owner. */
final readonly class ProtectedTextValue
{
    public function changed(ProtectedRevisionChanges $changes, ?BasicType $before, ?BasicType $after): bool
    {
        if (!$changes->same($before, $after)) {
            return true;
        }
        return $before instanceof IndirectReference && $after instanceof IndirectReference
            && !$changes->same($changes->prior->record($before)->snapshot, $changes->current->record($after)->snapshot);
    }

    public function read(ProtectedRevisionChanges $changes, FrozenFormField $field, string $key): ?StringType
    {
        $value = $key === 'V' ? $field->effective($key) : $field->dictionary->get($key);
        if ($value instanceof IndirectReference) {
            $record = $changes->current->record($value);
            if (!$changes->unchanged($value)) {
                // Sharing through a default value, parent, resource or a private alias is not filling.
                $changes->assertExclusiveFieldValue($record->identifier, $field->identifier, $key);
                if ($record->identifier->objectNumber->value < $changes->prior->revision->size->integer()) {
                    $changes->change($record->identifier);
                } else {
                    $changes->create($record->identifier);
                }
            }
            $value = $record->snapshot;
        }
        if ($value instanceof RevisionStreamSnapshot) {
            $version = new EffectivePdfVersion($changes->current->revision->header, $changes->current->catalog);
            if (version_compare($version->value->value, '1.5', '<')) {
                throw new \LogicException('Protected text streams require PDF 1.5 or later.');
            }
            foreach ($value->dictionary()->entries() as $entry) {
                if (!in_array($entry->key->value, ['Length', 'Filter', 'DecodeParms', 'DL'], true)) {
                    throw new \LogicException('Protected text streams contain no external files, metadata or unrelated dependencies.');
                }
                $changes->direct($entry->value);
            }
            return new LiteralString((new ProtectedStreamDecoder())->decode($value));
        }
        if ($value === null || $value instanceof NullType) {
            return null;
        }
        return $value instanceof StringType ? $value : throw new \LogicException('Protected text filling requires a text string or a bounded text stream.');
    }
}
