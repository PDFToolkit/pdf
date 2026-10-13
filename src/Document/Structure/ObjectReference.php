<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

use PDF\Specification\Definition\ObjectReferenceDictionaryTable325Definition;
use PDF\Specification\SchemaDictionary;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;

/** Whole-object content and its explicit StructParent key (tables 325–326, pages 572–573). */
final class ObjectReference extends SchemaDictionary
{
    public const string TABLE = '325';

    public function __construct(
        public readonly PageObject $page,
        public readonly DictionaryType|StreamObject $object,
        public readonly IntegerType $parentTreeKey,
    ) {
        if ($parentTreeKey->integer() < 0) {
            throw new \InvalidArgumentException('A parent-tree key must be non-negative.');
        }
        parent::__construct(
            new ObjectReferenceDictionaryTable325Definition(),
            new Entry(new NameType('Type'), new NameType('OBJR')),
            new Entry(new NameType('Pg'), new IndirectReference($page)),
            new Entry(new NameType('Obj'), new IndirectReference($object)),
        );
    }

    public function validateBinding(): void
    {
        $dictionary = $this->object instanceof StreamObject ? $this->object->dictionary() : $this->object;
        $key = $dictionary->get('StructParent');
        if (!$key instanceof IntegerType || $key->integer() !== $this->parentTreeKey->integer() || $dictionary->has('StructParents')) {
            throw new \InvalidArgumentException('An entire content object must carry exactly its explicit StructParent key, without StructParents.');
        }
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('An OBJR is determined by its explicit page and content object.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('An OBJR cannot lose its content object.');
    }
}
