<?php

declare(strict_types=1);

namespace PDF\Syntax\Linearization;

use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\StreamObject;

/** Per-plan lazy copies: indirect cycles retain actual object-valued targets and source identities remain untouched. */
final class ThumbnailGraphCopies
{
    private ObjectIdentityObjects $targets;
    private ObjectIdentityObjects $directDictionaries;

    public function __construct(private readonly ObjectIdentitySet $mixed)
    {
        $this->targets = new ObjectIdentityObjects();
        $this->directDictionaries = new ObjectIdentityObjects();
    }

    public function needsCopy(BasicType $value): bool
    {
        return $this->mixed->has($value);
    }

    public function value(BasicType $value): BasicType
    {
        if ($value instanceof IndirectReference) {
            return $this->mixed->has($value->target()) ? new IndirectReference($this->target($value->target())) : $value;
        }
        if ($value instanceof DictionaryType) {
            if (!$this->directDictionaries->has($value)) {
                $this->directDictionaries->assign($value, new ThumbnailDictionaryCopy($value, $this));
            }
            return $this->directDictionaries->value($value);
        }
        if ($value instanceof ArrayType) {
            return new ArrayType(...array_map(fn (BasicType $item): BasicType => $this->value($item), $value->items));
        }
        return $value;
    }

    public function root(StreamObject $value): ThumbnailStreamCopy
    {
        // A page's image itself is a distinct output value. Mixed dependencies
        // are shared by these roots only inside the thumbnail subsection.
        return new ThumbnailStreamCopy($value, $this);
    }

    private function target(BasicType $value): BasicType
    {
        if (!$this->targets->has($value)) {
            $copy = $value instanceof StreamObject ? new ThumbnailStreamCopy($value, $this)
                : ($value instanceof DictionaryType ? new ThumbnailDictionaryCopy($value, $this)
                    : ($value instanceof ArrayType ? new ArrayType(...array_map(fn (BasicType $item): BasicType => $this->value($item), $value->items)) : clone $value));
            $this->targets->assign($value, $copy);
        }
        return $this->targets->value($value);
    }
}
