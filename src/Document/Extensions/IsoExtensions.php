<?php

declare(strict_types=1);

namespace PDF\Document\Extensions;

use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;

/** Multiple independent ISO extension levels are an array, never a maximum level. */
final class IsoExtensions extends DictionaryType implements \PDF\Document\VersionedComponent
{
    public function __construct(IsoTechnicalSpecification ...$specifications)
    {
        if ($specifications === []) {
            throw new \InvalidArgumentException('At least one ISO technical specification is required.');
        }
        $values = [];
        foreach ($specifications as $specification) {
            if (isset($values[$specification->value])) {
                throw new \InvalidArgumentException('An ISO extension level cannot be declared twice.');
            }
            $values[$specification->value] = $specification->dictionary();
        }
        parent::__construct(new Entry(new NameType('ISO_'), new ArrayType(...array_values($values))));
    }

    public function validateVersion(EffectivePdfVersion $version): void
    {
        $version->require(PdfVersion::PDF20, 'ISO technical specification extensions');
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('Construct a new explicit ISO extensions collection.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('Construct a new explicit ISO extensions collection.');
    }
}
