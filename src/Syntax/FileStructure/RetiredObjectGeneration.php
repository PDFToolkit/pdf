<?php

declare(strict_types=1);

namespace PDF\Syntax\FileStructure;

/** Frozen tombstone: aliases keep this obsolete generation and never become aliases of a reused value. */
final readonly class RetiredObjectGeneration
{
    public function __construct(public RevisionObject $object, public ?ObjectByteSpan $span = null)
    {
        if ($span !== null && (string) $span->identifier !== (string) $object->identifier) {
            throw new \InvalidArgumentException('A retired span belongs to its exact retired generation.');
        }
    }
}
