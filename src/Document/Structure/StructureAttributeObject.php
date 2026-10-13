<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

use PDF\Specification\Definition\AttributeObjectDictionaryTable327Definition;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;

/** An immutable association with an explicit dictionary or opaque stream attribute object. */
final readonly class StructureAttributeObject
{
    public NameType $owner;

    public function __construct(public DictionaryType|StreamObject|IndirectReference $object)
    {
        $dictionary = $this->dictionary();
        $owner = $dictionary->get('O');
        if (!$owner instanceof NameType) {
            throw new \InvalidArgumentException('An attribute object requires an explicit owner /O (table 327).');
        }
        $this->owner = $owner;
        $standard = new StandardStructureAttributes();
        $standard->validate($dictionary, $owner);
        $definition = $standard->definition($owner) ?? new AttributeObjectDictionaryTable327Definition();
        $definition->entry('O')->type->validate($owner);
        foreach ($definition->entries() as $entry) {
            if ($entry->requirement->unconditional() && !$dictionary->has($entry->name)) {
                throw new \InvalidArgumentException('An attribute object is missing /' . $entry->name . '.');
            }
        }
    }

    public function dictionary(): DictionaryType
    {
        $object = $this->object instanceof IndirectReference ? $this->object->target() : $this->object;
        if ($object instanceof StreamObject) {
            return $object->dictionary();
        }
        if (!$object instanceof DictionaryType) {
            throw new \InvalidArgumentException('An attribute object must be a dictionary or stream.');
        }
        return $object;
    }

    public function value(): BasicType
    {
        return $this->object instanceof StreamObject ? new IndirectReference($this->object) : $this->object;
    }
}
