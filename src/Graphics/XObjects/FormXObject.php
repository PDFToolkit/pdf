<?php

declare(strict_types=1);

namespace PDF\Graphics\XObjects;

use PDF\Document\ResourceDictionary;
use PDF\Graphics\CoordinateSystems\Matrix;
use PDF\Graphics\OptionalContent\OptionalContentObject;
use PDF\Specification\Definition\Type1FormDictionaryTable95Definition;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\Filters\EncodedData;
use PDF\Syntax\Filters\Filter;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;

/** Independent native form/proxy XObject, with explicit resources and group context. */
final class FormXObject extends StreamObject
{
    /** @param list<Entry> $extensions */
    public function __construct(ContentStreams $contents, Rectangle $boundingBox, public readonly ResourceDictionary $resources, Matrix $matrix = new Matrix(), public readonly ?TransparencyGroup $group = null, ?ReferenceDictionary $reference = null, ?OptionalContentObject $optionalContent = null, ?IntegerType $structParent = null, ?IntegerType $structParents = null, array $extensions = [], Filter ...$filters)
    {
        $contents->validate();
        (new XObjectResourcesValidator())->validate($contents, $resources);
        if (($structParent !== null && $structParents !== null) || ($structParent !== null && $structParent->integer() < 0) || ($structParents !== null && $structParents->integer() < 0)) {
            throw new \InvalidArgumentException('A form is either one structural content item or a marked-content container, with nonnegative parent-tree keys.');
        }
        $group?->validateForm();
        foreach ($filters as $filter) {
            if ($filter instanceof EncodedData) {
                throw new \InvalidArgumentException('Typed form content cannot use an external encoded-data declaration.');
            }
        }
        $keys = [];
        foreach ($extensions as $entry) {
            if (!$entry instanceof Entry || in_array($entry->key->value, ['Type', 'Subtype', 'FormType', 'BBox', 'Matrix', 'Resources', 'Group', 'Ref', 'OC', 'StructParent', 'StructParents'], true)) {
                throw new \InvalidArgumentException('Form structural entries are provided by explicit constructor values.');
            }
            $keys[$entry->key->value] = true;
        }
        if (isset($keys['PieceInfo']) && !isset($keys['LastModified'])) {
            throw new \InvalidArgumentException('A form PieceInfo requires LastModified.');
        }
        $entries = [new Entry(new NameType('Type'), new NameType('XObject')), new Entry(new NameType('Subtype'), new NameType('Form')), new Entry(new NameType('FormType'), new IntegerType(1)), new Entry(new NameType('BBox'), $boundingBox), new Entry(new NameType('Matrix'), new ArrayType(...$matrix->operands())), new Entry(new NameType('Resources'), $resources), ...$extensions];
        foreach (['Group' => $group, 'Ref' => $reference, 'OC' => $optionalContent === null ? null : new IndirectReference($optionalContent), 'StructParent' => $structParent, 'StructParents' => $structParents] as $name => $value) {
            if ($value !== null) {
                $entries[] = new Entry(new NameType($name), $value);
            }
        }
        parent::__construct($contents, new XObjectDictionary(new Type1FormDictionaryTable95Definition(), ...$entries), ...$filters);
    }
}
