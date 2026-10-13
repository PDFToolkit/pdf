<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Syntax\Object\NullType;

/** An actual field from the effective frozen AcroForm tree, including inherited FT/V. */
final readonly class FrozenSignatureField
{
    /** @param array<string, BasicType> $inherited */
    public function __construct(public ObjectIdentifier $identifier, public DictionaryType $dictionary, public string $qualifiedName, private array $inherited)
    {
    }

    public function effective(string $key): ?BasicType
    {
        $value = $this->dictionary->get($key);
        return $this->inherited[$key] ?? ($value instanceof NullType ? null : $value);
    }
}
