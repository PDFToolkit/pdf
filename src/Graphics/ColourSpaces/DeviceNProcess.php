<?php

declare(strict_types=1);

namespace PDF\Graphics\ColourSpaces;

use PDF\Specification\Definition\DeviceNProcessDictionaryTable72Definition;
use PDF\Specification\SchemaDictionary;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;

/** Ordered named process components and their device/CIE colour space (table 72, page 171). */
final class DeviceNProcess extends SchemaDictionary
{
    public const string TABLE = '72';
    public function __construct(public readonly ColourSpace $colourSpace, public readonly ColourComponentNames $components)
    {
        if ($colourSpace->family()->special() || count($components) !== $colourSpace->componentCount()) {
            throw new \InvalidArgumentException('A process dictionary needs one name per device or CIE colour component.');
        }
        $names = array_map(static fn (NameType $name): string => $name->value, $components->names);
        if (count(array_unique($names)) !== count($names)) {
            throw new \InvalidArgumentException('Process component names must be unique.');
        }
        parent::__construct(new DeviceNProcessDictionaryTable72Definition(), new Entry(new NameType('ColorSpace'), $colourSpace->value()), new Entry(new NameType('Components'), $components));
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('Process colour entries are validated together in their constructor.');
    }

    /** PDF's allowed four-component ICC source model is CMYK (table 68, PDF page 159). */
    public function isCMYK(): bool
    {
        return $this->colourSpace->family() === ColourSpaceFamily::DeviceCMYK || ($this->colourSpace instanceof ICCBased && $this->colourSpace->profile->components->integer() === 4);
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('Process colour entries cannot be removed independently.');
    }
}
