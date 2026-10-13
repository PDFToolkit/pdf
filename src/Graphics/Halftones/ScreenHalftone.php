<?php

declare(strict_types=1);

namespace PDF\Graphics\Halftones;

use PDF\Specification\Definition\Type1HalftoneDictionaryTable130Definition;
use PDF\Specification\SchemaDictionary;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;

final class ScreenHalftone extends SchemaDictionary implements HalftoneObject, \PDF\Document\VersionedComponent
{
    public function validateVersion(\PDF\Syntax\FileStructure\EffectivePdfVersion $version): void
    {
        $version->require(\PDF\Syntax\FileStructure\PdfVersion::PDF12, 'Halftone screen dictionary');
    }

    public const string TABLE = '130';

    public function __construct(NumericType $frequency, NumericType $angle, SpotFunction $spotFunction, public readonly HalftoneOptions $options = new HalftoneOptions(), BooleanType $accurateScreens = new BooleanType(false))
    {
        if ($frequency->value <= 0) {
            throw new \InvalidArgumentException('Screen frequency must be positive.');
        }
        parent::__construct(new Type1HalftoneDictionaryTable130Definition(), new Entry(new NameType('Type'), new NameType('Halftone')), new Entry(new NameType('HalftoneType'), new IntegerType(1)), new Entry(new NameType('Frequency'), $frequency), new Entry(new NameType('Angle'), $angle), new Entry(new NameType('SpotFunction'), $spotFunction->value()), new Entry(new NameType('AccurateScreens'), $accurateScreens), ...$options->entries());
    }

    public function halftoneType(): int
    {
        return 1;
    }

    public function hasTransferFunction(): bool
    {
        return $this->options->transfer !== null;
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('Halftone screen declarations are constructor-controlled.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('Halftone screen declarations are constructor-controlled.');
    }
}
