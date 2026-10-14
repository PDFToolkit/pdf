<?php

declare(strict_types=1);

namespace PDF\Document\Files;

use PDF\Syntax\FileStructure\RevisionValueSnapshot;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;

/** Frozen AF property operands for one BDC/DP instruction, not interpreted stream bytes. */
final readonly class AssociatedFilesMarkedContentUse
{
    public NameType|DictionaryType $properties;

    public function __construct(public string $symbol, NameType|DictionaryType $properties, public bool $declared = false)
    {
        if (!in_array($symbol, ['BDC', 'DP'], true) || ($declared && ($symbol !== 'BDC' || !$properties instanceof NameType))) {
            throw new \InvalidArgumentException('An associated-file use has BDC/DP properties; a declared association uses a named BDC property list.');
        }
        $frozen = (new RevisionValueSnapshot())->capture($properties);
        $this->properties = $frozen instanceof NameType || $frozen instanceof DictionaryType ? $frozen : throw new \LogicException('Property operands retain their name or dictionary type.');
    }
}
