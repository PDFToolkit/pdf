<?php

declare(strict_types=1);

namespace PDF\Syntax\Linearization;

use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\StreamObject;

/** Explicit permission for output-only thumbnail value copies required by F.3.10/F.4.3. */
final readonly class ThumbnailGraphNormalization
{
    /**
     * @param list<BasicType> $objects
     * @param non-empty-list<DictionaryType> $pages
     * @param array<int, list<BasicType>> $closures
     */
    public function copies(array $objects, array $pages, array $closures, ObjectIdentitySet $alreadyPlaced): ThumbnailGraphCopies
    {
        $thumbnailObjects = new ObjectIdentitySet();
        foreach ($closures as $closure) {
            foreach ($closure as $object) {
                $thumbnailObjects->add($object);
            }
        }
        $mixed = new ObjectIdentitySet();
        $pending = [];
        foreach ($objects as $object) {
            if (!$thumbnailObjects->has($object) || $alreadyPlaced->has($object)) {
                if ($thumbnailObjects->has($object)) {
                    $mixed->add($object);
                    $pending[] = $object;
                }
                foreach ($this->references($object, $pages) as $target) {
                    if ($thumbnailObjects->has($target) && !$mixed->has($target)) {
                        $mixed->add($target);
                        $pending[] = $target;
                    }
                }
            }
        }
        // A mixed object's descendants also belong to its unchanged external
        // representation, so their thumbnail copies must remain exclusive too.
        while ($pending !== []) {
            $object = array_pop($pending);
            foreach ($this->references($object, $pages) as $target) {
                if ($thumbnailObjects->has($target) && !$mixed->has($target)) {
                    $mixed->add($target);
                    $pending[] = $target;
                }
            }
        }
        return new ThumbnailGraphCopies($mixed);
    }

    /**
     * @param list<DictionaryType> $pages
     * @return list<BasicType>
     */
    private function references(BasicType $root, array $pages): array
    {
        $targets = [];
        $pending = [$root];
        $seen = new ObjectIdentitySet();
        while ($pending !== []) {
            $value = array_pop($pending);
            if ($value instanceof IndirectReference) {
                $targets[] = $value->target();
                continue;
            }
            if ($seen->has($value)) {
                continue;
            }
            $seen->add($value);
            if ($value instanceof StreamObject) {
                $pending[] = $value->dictionary();
                foreach ($value->filters as $filter) {
                    if (($parameters = $filter->parameters()) !== null) {
                        $pending[] = $parameters;
                    }
                }
            } elseif ($value instanceof ArrayType) {
                array_push($pending, ...$value->items);
            } elseif ($value instanceof DictionaryType) {
                foreach ($value->entries() as $entry) {
                    if ($entry->key->value !== 'Thumb' || !in_array($value, $pages, true)) {
                        $pending[] = $entry->value;
                    }
                }
            }
        }
        return $targets;
    }
}
