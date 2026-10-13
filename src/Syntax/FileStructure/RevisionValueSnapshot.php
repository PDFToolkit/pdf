<?php

declare(strict_types=1);

namespace PDF\Syntax\FileStructure;

use PDF\Serialization\SerializationContext;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\StreamObject;

/** Freeze direct containers while preserving explicit indirect identities across revisions. */
final class RevisionValueSnapshot
{
    /** @var \SplObjectStorage<BasicType, BasicType> */
    private \SplObjectStorage $copies;
    /** @var \SplObjectStorage<BasicType, null> */
    private \SplObjectStorage $active;

    public function __construct(private readonly ?SerializationContext $context = null)
    {
        $this->copies = new \SplObjectStorage();
        $this->active = new \SplObjectStorage();
    }

    public function capture(BasicType $value): BasicType
    {
        if ($value instanceof IndirectReference && $this->context !== null) {
            return new IndirectReference($value->target(), $this->context->reference($value));
        }
        if ($value instanceof IndirectReference || (!$value instanceof DictionaryType && !$value instanceof ArrayType)) {
            if ($value instanceof StreamObject) {
                throw new \LogicException('A direct revision value cannot contain a stream.');
            }
            return $value;
        }
        if ($this->active->offsetExists($value)) {
            throw new \LogicException('A direct revision container cycle requires an indirect reference.');
        }
        if ($this->copies->offsetExists($value)) {
            return $this->copies[$value];
        }
        $this->active->offsetSet($value);
        try {
            if ($value instanceof ArrayType) {
                $copy = new ArrayType(...array_map($this->capture(...), $value->items));
            } else {
                $entries = [];
                foreach ($value->entries() as $entry) {
                    $entries[] = new Entry($entry->key, $this->capture($entry->value));
                }
                $copy = new DictionaryType(...$entries);
            }
            $this->copies[$value] = $copy;
            // Captured plain containers are immutable values. Reusing this
            // capture object preserves their identity during graph projection.
            $this->copies[$copy] = $copy;
            return $copy;
        } finally {
            $this->active->offsetUnset($value);
        }
    }
}
