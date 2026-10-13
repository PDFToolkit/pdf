<?php

declare(strict_types=1);

namespace PDF\Graphics\GraphicsState;

use PDF\Functions\FunctionObject;
use PDF\Graphics\ColourSpaces\ColourComponents;
use PDF\Graphics\XObjects\FormXObject;
use PDF\Specification\Definition\SoftMaskDictionaryTable144Definition;
use PDF\Specification\SchemaDictionary;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;

/** Alpha/luminosity mask deriving values from an explicit transparency-group form. */
final class SoftMask extends SchemaDictionary implements \PDF\Document\VersionedComponent
{
    public function validateVersion(\PDF\Syntax\FileStructure\EffectivePdfVersion $version): void
    {
        $version->require(\PDF\Syntax\FileStructure\PdfVersion::PDF14, 'Soft-mask dictionary');
    }

    public const string TABLE = '144';

    public function __construct(public readonly SoftMaskSubtype $subtype, public readonly FormXObject $group, ?ColourComponents $backdrop = null, ?FunctionObject $transfer = null)
    {
        if ($group->group === null || ($subtype === SoftMaskSubtype::Luminosity && $group->group->colourSpace === null)) {
            throw new \InvalidArgumentException('Soft masks require a transparency group; luminosity masks require its explicit blending space.');
        }
        if ($backdrop !== null && ($group->group->colourSpace === null || $backdrop->space !== $group->group->colourSpace->space)) {
            throw new \InvalidArgumentException('A mask backdrop needs the group blending-space components.');
        }
        if ($transfer !== null) {
            (new ScalarColourFunction($transfer))->validate();
        }
        $entries = [new Entry(new NameType('Type'), new NameType('Mask')), new Entry(new NameType('S'), new NameType($subtype->value)), new Entry(new NameType('G'), new IndirectReference($group)), new Entry(new NameType('TR'), $transfer === null ? new NameType('Identity') : new IndirectReference($transfer))];
        if ($backdrop !== null) {
            $entries[] = new Entry(new NameType('BC'), $backdrop);
        }
        parent::__construct(new SoftMaskDictionaryTable144Definition(), ...$entries);
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('Soft-mask group/subtype/backdrop declarations are coupled.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('Soft-mask group/subtype/backdrop declarations are coupled.');
    }
}
