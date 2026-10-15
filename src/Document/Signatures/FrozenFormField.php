<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\FileStructure\RevisionObject;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;

/** One terminal field and its actual widget ownership in an emitted AcroForm. */
final readonly class FrozenFormField
{
    /** @param array<string, BasicType> $inherited
     * @param list<RevisionObject> $widgets
     * @param list<string> $owners
     */
    public function __construct(public ObjectIdentifier $identifier, public DictionaryType $dictionary, public string $qualifiedName, private array $inherited, public array $widgets, public array $owners)
    {
    }

    public function effective(string $key): ?BasicType
    {
        return $this->inherited[$key] ?? null;
    }
}
