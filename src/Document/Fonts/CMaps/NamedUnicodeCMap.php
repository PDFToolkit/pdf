<?php

declare(strict_types=1);

namespace PDF\Document\Fonts\CMaps;

use PDF\Syntax\Object\NameType;

/** Explicit external reader resource; byte-to-Unicode resolution requires supplied mappings. */
final readonly class NamedUnicodeCMap implements UnicodeCMapResource
{
    public function __construct(private CMapName $resourceName, private CodeSpaces $spaces, private ?UnicodeMappings $resolvedMappings = null)
    {
        if ($spaces->ranges === []) {
            throw new \InvalidArgumentException('A named ToUnicode resource needs its declared nonempty codespace.');
        }
        $resolvedMappings?->validate($spaces);
    }
    public function object(): NameType
    {
        return $this->resourceName->value;
    }
    public function name(): CMapName
    {
        return $this->resourceName;
    }
    public function codeSpaces(): CodeSpaces
    {
        return $this->spaces;
    }
    public function sequence(CMapCode $code): ?UnicodeSequence
    {
        if ($this->resolvedMappings === null) {
            throw new \LogicException('Named ToUnicode resolution requires explicitly supplied resource mappings.');
        }
        return $this->resolvedMappings->sequence($code);
    }
}
