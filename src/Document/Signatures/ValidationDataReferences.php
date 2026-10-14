<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\IndirectReference;

final class ValidationDataReferences extends ArrayType
{
    public function __construct(public readonly ValidationDataKind $kind, IndirectReference ...$references)
    {
        if ($references === []) {
            throw new \InvalidArgumentException('Empty validation data arrays are omitted.');
        }
        $seen = new \SplObjectStorage();
        foreach ($references as $reference) {
            $target = $reference->target();
            if (!$target instanceof ValidationDataStream || $target->kind !== $kind || $seen->offsetExists($target)) {
                throw new \InvalidArgumentException('Validation references identify distinct streams of the declared evidence kind.');
            }
            $seen->offsetSet($target);
        }
        parent::__construct(...$references);
    }
}
