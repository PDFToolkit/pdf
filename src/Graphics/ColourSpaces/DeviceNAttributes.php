<?php

declare(strict_types=1);

namespace PDF\Graphics\ColourSpaces;

use PDF\Specification\Definition\DeviceNColourSpaceAttributesDictionaryTable71Definition;
use PDF\Specification\SchemaDictionary;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;

/** Declared DeviceN/NChannel colorants, process mapping and ink hints (tables 71–73). */
final class DeviceNAttributes extends SchemaDictionary
{
    public const string TABLE = '71';

    public function __construct(public readonly NameType $subtype = new NameType('DeviceN'), public readonly ?ColorantDictionary $colorants = null, public readonly ?DeviceNProcess $process = null, public readonly ?DeviceNMixingHints $mixingHints = null)
    {
        if (!in_array($subtype->value, ['DeviceN', 'NChannel'], true)) {
            throw new \InvalidArgumentException('DeviceN attributes Subtype must be DeviceN or NChannel.');
        }
        $entries = [new Entry(new NameType('Subtype'), $subtype)];
        foreach (['Colorants' => $colorants, 'Process' => $process, 'MixingHints' => $mixingHints] as $name => $value) {
            if ($value !== null) {
                $entries[] = new Entry(new NameType($name), $value);
            }
        }
        parent::__construct(new DeviceNColourSpaceAttributesDictionaryTable71Definition(), ...$entries);
    }

    public function validateComponents(ColourComponentNames $components): void
    {
        $this->mixingHints?->validateComponents($components);
        if ($this->subtype->value !== 'NChannel') {
            return;
        }
        $names = array_map(static fn (NameType $name): string => $name->value, $components->names);
        if (in_array('None', $names, true)) {
            throw new \InvalidArgumentException('NChannel cannot contain the None colorant.');
        }
        $processNames = $this->process === null ? [] : array_map(static fn (NameType $name): string => $name->value, $this->process->components->names);
        if ($this->process !== null && !$this->process->isCMYK()) {
            $first = array_search($processNames[0], $names, true);
            if ($first === false || array_slice($names, $first, count($processNames)) !== $processNames) {
                throw new \InvalidArgumentException('Non-CMYK NChannel process names must appear consecutively in their colour-space order.');
            }
        }
        if ($this->process === null && array_intersect($names, ['Cyan', 'Magenta', 'Yellow', 'Black']) !== []) {
            throw new \InvalidArgumentException('NChannel process components require an explicit process dictionary.');
        }
        foreach ($names as $name) {
            $cmykReserved = $this->process !== null && $this->process->isCMYK() && in_array($name, ['Cyan', 'Magenta', 'Yellow', 'Black'], true);
            if (!in_array($name, $processNames, true) && !$cmykReserved && !($this->colorants?->get($name) instanceof Separation)) {
                throw new \InvalidArgumentException('NChannel requires a Separation definition for every spot colorant.');
            }
        }
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('DeviceN attributes are validated together with their component names.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('DeviceN attribute entries cannot be removed independently.');
    }
}
