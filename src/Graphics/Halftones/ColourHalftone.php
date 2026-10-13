<?php

declare(strict_types=1);

namespace PDF\Graphics\Halftones;

use PDF\Specification\Definition\Type5HalftoneDictionaryTable134Definition;
use PDF\Specification\SchemaDictionary;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringType;

/** Type5 independent colorant screens, with required transfer functions for spot/default. */
final class ColourHalftone extends SchemaDictionary implements HalftoneObject, \PDF\Document\VersionedComponent
{
    public function validateVersion(\PDF\Syntax\FileStructure\EffectivePdfVersion $version): void
    {
        $version->require(\PDF\Syntax\FileStructure\PdfVersion::PDF12, 'Colour halftone dictionary');
    }

    public const string TABLE = '134';
    /** @var array<string, HalftoneObject> */
    private readonly array $components;

    public function __construct(public readonly HalftoneObject $default, ?StringType $name = null, HalftoneComponent ...$components)
    {
        if ($default->halftoneType() === 5) {
            throw new \InvalidArgumentException('A type5 default must not itself be type5.');
        }
        $map = [];
        $entries = [new Entry(new NameType('Type'), new NameType('Halftone')), new Entry(new NameType('HalftoneType'), new IntegerType(5)), new Entry(new NameType('Default'), new IndirectReference($default))];
        foreach ($components as $component) {
            if (isset($map[$component->colorant->value]) || ($component->nonprimary() && !$default->hasTransferFunction())) {
                throw new \InvalidArgumentException('Type5 colorants must be unique; nonprimary colorants require a transfer function on Default too.');
            }
            $map[$component->colorant->value] = $component->halftone;
            $entries[] = new Entry($component->colorant, new IndirectReference($component->halftone));
        }
        if ($name !== null) {
            $entries[] = new Entry(new NameType('HalftoneName'), $name);
        }
        $this->components = $map;
        parent::__construct(new Type5HalftoneDictionaryTable134Definition(), ...$entries);
    }

    public function forColorant(NameType $colorant): HalftoneObject
    {
        return $this->components[$colorant->value] ?? $this->default;
    }

    public function halftoneType(): int
    {
        return 5;
    }

    public function hasTransferFunction(): bool
    {
        return false;
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('Type5 colorant screens are coupled constructor values.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('Type5 colorant screens are coupled constructor values.');
    }
}
