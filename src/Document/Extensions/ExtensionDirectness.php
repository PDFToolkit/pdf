<?php

declare(strict_types=1);

namespace PDF\Document\Extensions;

use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\StreamObject;

/** ISO 32000-1, 7.12.1: extension declarations are nested without indirect objects. */
final readonly class ExtensionDirectness
{
    public function validate(BasicType $value): void
    {
        /** @var \SplObjectStorage<BasicType, null> $active */
        $active = new \SplObjectStorage();
        /** @var \SplObjectStorage<BasicType, null> $checked */
        $checked = new \SplObjectStorage();
        $this->visit($value, $active, $checked);
    }

    /**
     * @param \SplObjectStorage<BasicType, null> $active
     * @param \SplObjectStorage<BasicType, null> $checked
     */
    private function visit(BasicType $value, \SplObjectStorage $active, \SplObjectStorage $checked): void
    {
        if ($value instanceof IndirectReference || $value instanceof StreamObject) {
            throw new \InvalidArgumentException('Extension declarations require direct objects.');
        }
        if ($active->offsetExists($value)) {
            throw new \InvalidArgumentException('An extension declaration cannot contain a direct cycle.');
        }
        if ($checked->offsetExists($value)) {
            return;
        }
        $active->offsetSet($value);
        try {
            if ($value instanceof ArrayType) {
                foreach ($value->items as $item) {
                    $this->visit($item, $active, $checked);
                }
            } elseif ($value instanceof DictionaryType) {
                foreach ($value->entries() as $entry) {
                    $this->visit($entry->value, $active, $checked);
                }
            }
            $checked->offsetSet($value);
        } finally {
            $active->offsetUnset($value);
        }
    }
}
