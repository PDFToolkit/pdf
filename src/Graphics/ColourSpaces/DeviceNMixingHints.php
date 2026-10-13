<?php

declare(strict_types=1);

namespace PDF\Graphics\ColourSpaces;

use PDF\Specification\Definition\DeviceNMixingHintsDictionaryTable73Definition;
use PDF\Specification\SchemaDictionary;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;

/** PDF-native blending declarations and required printing order (table 73, PDF pages 171–172). */
final class DeviceNMixingHints extends SchemaDictionary
{
    public const string TABLE = '73';

    public function __construct(public readonly ?InkSolidities $solidities = null, public readonly ?ColourComponentNames $printingOrder = null, public readonly ?DotGainFunctions $dotGain = null)
    {
        if ($solidities !== null && $printingOrder === null) {
            throw new \InvalidArgumentException('MixingHints.PrintingOrder is required whenever Solidities is present.');
        }
        $entries = [];
        foreach (['Solidities' => $solidities, 'PrintingOrder' => $printingOrder, 'DotGain' => $dotGain] as $name => $value) {
            if ($value !== null) {
                $entries[] = new Entry(new NameType($name), $value);
            }
        }
        parent::__construct(new DeviceNMixingHintsDictionaryTable73Definition(), ...$entries);
    }

    public function validateComponents(ColourComponentNames $components): void
    {
        if ($this->printingOrder === null) {
            return;
        }
        $order = array_map(static fn (NameType $name): string => $name->value, $this->printingOrder->names);
        foreach ($components->names as $component) {
            if (!in_array($component->value, $order, true)) {
                throw new \InvalidArgumentException('PrintingOrder must include every DeviceN component name.');
            }
        }
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('Mixing-hint entries are validated together.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('Mixing-hint entries cannot be removed independently.');
    }
}
