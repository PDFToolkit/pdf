<?php

declare(strict_types=1);

namespace PDF\Syntax\FileStructure;

use PDF\Serialization\SerializationContext;
use PDF\Document\Files\AssociatedFilesMarkedContentFacts;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\StreamObject;

/** Actual encoded plaintext emitted once, with the exact final dictionary frozen before serialization. */
final class RevisionStreamSnapshot extends StreamObject
{
    public function __construct(private readonly DictionaryType $emittedDictionary, public readonly string $encodedBytes, public readonly ?AssociatedFilesMarkedContentFacts $markedContentFacts = null)
    {
        parent::__construct($encodedBytes);
    }

    public function dictionary(): DictionaryType
    {
        return $this->emittedDictionary;
    }

    public function bytes(SerializationContext $context): string
    {
        return $this->encodedBytes;
    }

    public function toPdf(SerializationContext $context): string
    {
        throw new \LogicException('An emitted stream snapshot is evidence; explicitly construct a stream to write new bytes.');
    }
}
