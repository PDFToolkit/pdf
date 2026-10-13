<?php

declare(strict_types=1);

namespace PDF\Syntax\FileStructure;

use PDF\Serialization\SerializationContext;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject;
use PDF\Syntax\Object\IntegerType;

/** Preserve logical identities while omitting required legacy-visible members from physical object-stream copies. */
final class HybridReferenceObjects
{
    /** @var \SplObjectStorage<BasicType, BasicType> */
    private \SplObjectStorage $values;
    /** @var array<int, CrossReferenceStreamCompressedEntry> */
    private array $compressed = [];

    public function __construct(SerializationContext $context, DictionaryType $catalog, ?FileRevision $previous = null)
    {
        $this->values = new \SplObjectStorage();
        $visible = (new HybridVisibleGraph($context, $catalog, $previous))->objectNumbers();
        if ($previous !== null) {
            $ordinary = [];
            foreach ($previous->spans() as $span) {
                $ordinary[$span->identifier->objectNumber->value] = true;
            }
            foreach ($previous->objects() as $record) {
                $number = $record->identifier->objectNumber->value;
                if (isset($visible[$number]) && !isset($ordinary[$number]) && !isset($context->objects()[$number])) {
                    // A previously optional compressed value may become required.
                    // Stage its frozen dictionary at the same live identifier.
                    $context->stage(new IndirectObject($record->snapshot, $record->identifier));
                }
            }
        }
        foreach ($context->objects() as $object) {
            if (!$object instanceof ObjectStream) {
                continue;
            }
            $members = [];
            foreach ($object->embeddedEntries() as $member) {
                if (isset($visible[$member->objectNumber->value])
                    || ($member->value instanceof DictionaryType && $context->isByteRangeSignature($member->value))) {
                    continue;
                }
                $members[] = $member;
                if (isset($this->compressed[$member->objectNumber->value])) {
                    throw new \LogicException('A logical object cannot belong to multiple hybrid object streams.');
                }
                $this->compressed[$member->objectNumber->value] = new CrossReferenceStreamCompressedEntry(
                    $member->objectNumber,
                    $context->identifier($object)->objectNumber,
                    new IntegerType(count($members) - 1)
                );
            }
            $this->values[$object] = new ObjectStream(...[...$members, ...$object->filters]);
        }
    }

    public function serializedValue(BasicType $object): BasicType
    {
        return $this->values->offsetExists($object) ? $this->values[$object] : $object;
    }

    /** @return array<int, CrossReferenceStreamCompressedEntry> */
    public function compressedEntries(): array
    {
        return $this->compressed;
    }
}
