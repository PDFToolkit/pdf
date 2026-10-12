<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Serialization\SerializationContext;
use PDF\Specification\Catalogue;
use PDF\Specification\Dictionary\CatalogDictionaryTable28;
use PDF\Specification\Dictionary\CatalogDictionaryTable28\PagesEntry;
use PDF\Specification\Dictionary\CatalogDictionaryTable28\TypeEntry;
use PDF\Specification\EntryDefinition;
use PDF\Specification\ObjectTypeConstraint;
use PDF\Specification\SchemaDictionary;
use PDF\Specification\SchemaEntry;
use PDF\Specification\TableDefinition;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringObject\LiteralString;
use PDF\Syntax\Object\StringType;
use PHPUnit\Framework\TestCase;

final class SpecificationTest extends TestCase
{
    public function testEveryGeneratedDefinitionCanConstructItsDeclaredObjectDomain(): void
    {
        $json = file_get_contents(dirname(__DIR__) . '/resources/iso32000-1/classes.json');
        self::assertIsString($json);
        $catalogue = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $entryCount = 0;
        $classNames = [];
        foreach ($catalogue as $dictionary) {
            $dictionaryObject = new ($dictionary['class'])();
            self::assertInstanceOf(SchemaDictionary::class, $dictionaryObject);
            $definition = new ($dictionary['definition_class'])();
            self::assertInstanceOf(TableDefinition::class, $definition);
            self::assertSame($dictionary['table'], $definition->source->table);
            self::assertFalse((new \ReflectionClass($definition))->isAbstract());
            foreach ($dictionary['entries'] as $entry) {
                $classNames[] = strtolower($entry['class']);
                $entryDefinition = $entry['variable'] ? $definition->variableEntry($entry['name']) : $definition->entry($entry['name']);
                $value = $this->sampleValue($entryDefinition);
                $object = $entry['variable']
                    ? new ($entry['class'])(new NameType($dictionary['table'] === '368' ? '1.3' : 'Custom'), $value)
                    : new ($entry['class'])($value);
                self::assertInstanceOf(SchemaEntry::class, $object);
                self::assertSame($value, $object->value());
                self::assertSame($entry['name'], $object->definition()->name);
                if (!$entry['variable']) {
                    self::assertSame($entry['name'], $object->key()->value);
                }
                $entryCount++;
            }
        }
        self::assertCount(263, $catalogue);
        self::assertSame(1405, $entryCount);
        self::assertCount(1405, array_unique($classNames), 'PDF name case distinctions must survive PHP class name case folding.');
    }

    public function testCatalogRequiresCanonicalNamesAndExplicitPageTreeReference(): void
    {
        $catalog = new CatalogDictionaryTable28(new TypeEntry(new NameType('Catalog')));
        try {
            $catalog->toPdf(new SerializationContext());
            self::fail('Missing required Pages was accepted.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('/Pages', $error->getMessage());
        }
        $pages = new IndirectReference(new DictionaryType());
        $complete = $catalog->with(new PagesEntry($pages));
        self::assertSame($pages, $complete->get('Pages'));
        self::assertFalse($catalog->has('Pages'));
        $complete->validate();
        $this->expectException(\InvalidArgumentException::class);
        new TypeEntry(new NameType('Bogus'));
    }

    public function testGenericEntriesCannotBypassKnownDictionaryConstraints(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CatalogDictionaryTable28(new Entry(new NameType('Pages'), new NameType('Wrong')));
    }

    public function testStreamsCannotAppearAsDirectDictionaryValues(): void
    {
        $constraint = new ObjectTypeConstraint('stream');
        $stream = new StreamObject('bytes');
        $constraint->validate(new IndirectReference($stream));
        $this->expectException(\InvalidArgumentException::class);
        $constraint->validate($stream);
    }

    public function testReferencesValidateTheTargetType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PagesEntry(new IndirectReference(new NameType('Wrong')));
    }

    public function testCrossReferenceDictionaryValuesAndArrayMembersRemainDirect(): void
    {
        $constraint = new ObjectTypeConstraint('array', direct: true, directArrayElements: true);
        $constraint->validate(new ArrayType(new IntegerType(1), new IntegerType(2), new IntegerType(1)));
        $this->expectException(\InvalidArgumentException::class);
        $constraint->validate(new ArrayType(new IndirectReference(new IntegerType(1))));
    }

    public function testRectangleShapeIsCheckedInAdditionToItsBasicArrayType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ObjectTypeConstraint('rectangle'))->validate(new ArrayType(new IntegerType(0), new IntegerType(1)));
    }

    public function testCatalogueInstancesHaveNoSharedRegistry(): void
    {
        $catalog = new CatalogDictionaryTable28();
        $first = new Catalogue($catalog->definition());
        $second = new Catalogue();
        self::assertSame($catalog->definition(), $first->dictionary('28'));
        $this->expectException(\OutOfBoundsException::class);
        $second->dictionary('28');
    }

    public function testTrailerOffsetsAreDirectAndCorrectionPreservesSourceWording(): void
    {
        $definition = new \PDF\Specification\Definition\FileTrailerDictionaryTable15Definition();
        $size = $definition->entry('Size');
        $prev = $definition->entry('Prev');
        self::assertTrue($size->type->direct);
        self::assertTrue($prev->type->direct);
        self::assertFalse($prev->type->indirect);
        self::assertStringContainsString('shall be an indirect reference', $prev->description);
        self::assertSame('trailer-prev-direct-object', $prev->correction?->id);
        $size->type->validate(new IntegerType(20));
        $prev->type->validate(new IntegerType(100));
        foreach ([$size, $prev] as $entry) {
            try {
                $entry->type->validate(new IndirectReference(new IntegerType(20)));
                self::fail('Indirect trailer offset was accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('direct object', $error->getMessage());
            }
        }
    }

    private function sampleValue(EntryDefinition $definition): BasicType
    {
        $class = $definition->type->classes[0];
        $value = match ($class) {
            NameType::class => new NameType($definition->type->fixedName ?? 'Value'),
            IntegerType::class => new IntegerType(1),
            NumericType::class => new NumericType(1.25),
            BooleanType::class => new BooleanType(true),
            DictionaryType::class => new DictionaryType(),
            StreamObject::class => new StreamObject('data'),
            StringType::class => new LiteralString('Value'),
            ArrayType::class => $definition->type->specificationType === 'rectangle'
                ? new ArrayType(new IntegerType(0), new IntegerType(0), new IntegerType(10), new IntegerType(10))
                : new ArrayType(),
            BasicType::class => new NullType(),
            default => throw new \LogicException('Missing representative object: ' . $class),
        };
        return $definition->type->indirect || $value instanceof StreamObject ? new IndirectReference($value) : $value;
    }
}
