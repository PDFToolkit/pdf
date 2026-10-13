<?php

declare(strict_types=1);

namespace PDF\Graphics\XObjects;

use PDF\Graphics\OptionalContent\OptionalContentObject;
use PDF\Specification\Definition\AlternateImageDictionaryTable91Definition;
use PDF\Specification\SchemaDictionary;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;

/** Alternative image declaration; alternatives cannot recursively define alternatives. */
final class AlternateImage extends SchemaDictionary
{
    public const string TABLE = '91';
    public function __construct(public readonly ImageXObject|EncodedImageXObject|ImageMask $image, public readonly BooleanType $defaultForPrinting = new BooleanType(false), ?OptionalContentObject $optionalContent = null)
    {
        if ($image->dictionary()->has('Alternates')) {
            throw new \InvalidArgumentException('An alternate image must not define its own Alternates.');
        }
        $entries = [new Entry(new NameType('Image'), new IndirectReference($image)), new Entry(new NameType('DefaultForPrinting'), $defaultForPrinting)];
        if ($optionalContent !== null) {
            $entries[] = new Entry(new NameType('OC'), new IndirectReference($optionalContent));
        }
        parent::__construct(new AlternateImageDictionaryTable91Definition(), ...$entries);
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('Alternate image declarations are constructor-controlled.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('Alternate image declarations are constructor-controlled.');
    }
}
