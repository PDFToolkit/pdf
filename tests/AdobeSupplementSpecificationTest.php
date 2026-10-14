<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Serialization\SerializationContext;
use PDF\Specification\Adobe\Definition\CollectionDictionaryTable8_6Definition;
use PDF\Specification\Adobe\Definition\FolderDictionaryTable8_6cDefinition;
use PDF\Specification\Adobe\Definition\RichMediaConfigurationDictionaryTable9_51aDefinition;
use PDF\Specification\Adobe\Definition\ThreeDViewDictionaryTable9_39Definition;
use PDF\Specification\Adobe\Dictionary\FolderDictionaryTable8_6c;
use PDF\Specification\Adobe\Dictionary\FolderDictionaryTable8_6c\IDEntry;
use PDF\Specification\Adobe\Dictionary\FolderDictionaryTable8_6c\NameEntry;
use PDF\Specification\Adobe\Dictionary\FolderDictionaryTable8_6c\ParentEntry;
use PDF\Specification\Adobe\Dictionary\FolderDictionaryTable8_6c\TypeEntry;
use PDF\Specification\Adobe\Dictionary\PointDataDictionaryTable8_111d\SubtypeEntry;
use PDF\Specification\Adobe\Dictionary\ViewerPreferencesDictionaryTable8_1\EnforceEntry;
use PDF\Specification\Catalogue;
use PDF\Specification\Definition\CollectionDictionaryTable155Definition;
use PDF\Specification\Definition\ViewerPreferencesDictionaryTable150Definition;
use PDF\Specification\EntryDefinition;
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

final class AdobeSupplementSpecificationTest extends TestCase
{
    public function testEverySupplementEntryConstructsItsDeclaredObjectDomain(): void
    {
        $catalogue = $this->inventory('classes.json');
        $metadata = $this->inventory('metadata.json');
        $entryCount = 0;
        $classNames = [];
        foreach ($catalogue as $table) {
            $dictionary = new ($table['class'])();
            $definition = new ($table['definition_class'])();
            self::assertInstanceOf(SchemaDictionary::class, $dictionary);
            self::assertInstanceOf(TableDefinition::class, $definition);
            self::assertSame($table['table'], $definition->source->table);
            self::assertSame($metadata['document'], $definition->source->document);
            self::assertSame($metadata['document_sha256'], $definition->source->documentSha256);
            self::assertSame($metadata['standard'], $definition->source->standard);
            foreach ($table['entries'] as $entry) {
                $entryDefinition = $entry['variable']
                    ? $definition->variableEntry($entry['name'])
                    : $definition->entry($entry['name']);
                $value = $this->sampleValue($entryDefinition);
                $object = $entry['variable']
                    ? new ($entry['class'])(new NameType('ADBE'), $value)
                    : new ($entry['class'])($value);
                self::assertInstanceOf(SchemaEntry::class, $object);
                self::assertSame($value, $object->value());
                self::assertSame($entry['name'], $object->definition()->name);
                self::assertSame($metadata['document'], $object->definition()->source->document);
                self::assertGreaterThanOrEqual(14, $object->definition()->source->pdfPage);
                self::assertLessThanOrEqual(100, $object->definition()->source->pdfPage);
                $classNames[] = strtolower($entry['class']);
                $entryCount++;
            }
        }
        self::assertCount(61, $catalogue);
        self::assertSame($metadata['dictionary_entries'] + $metadata['prose_specialization_entries'], $entryCount);
        self::assertCount($entryCount, array_unique($classNames));
        self::assertSame(
            $metadata['document_sha256'],
            hash_file('sha256', dirname(__DIR__) . '/' . $metadata['path']),
        );
    }

    public function testAdobeOverridesPreserveTheProvenanceOfInheritedIsoEntries(): void
    {
        $definition = new CollectionDictionaryTable8_6Definition();
        self::assertSame('8.6', $definition->entry('View')->source->table);
        self::assertSame('adobe_supplement_iso32000.pdf', $definition->entry('View')->source->document);
        self::assertStringContainsString(' C ', $definition->entry('View')->description);
        self::assertSame('155', $definition->entry('Schema')->source->table);
        self::assertSame('PDF32000_2008.pdf', $definition->entry('Schema')->source->document);
        self::assertTrue($definition->has('D'));
        self::assertTrue($definition->has('Sort'));
        self::assertFalse((new CollectionDictionaryTable155Definition())->has('Navigator'));
        self::assertSame('Collection', $definition->entry('Type')->type->fixedName);
    }

    public function testSeparateTableIdentifiersCoexistWithoutChangingIsoDefinitions(): void
    {
        $iso = new ViewerPreferencesDictionaryTable150Definition();
        $adobeClass = $this->classForTable('8.1', 'definition_class');
        $adobe = new $adobeClass();
        $catalogue = new Catalogue($iso, $adobe);
        self::assertSame($iso, $catalogue->dictionary('150'));
        self::assertSame($adobe, $catalogue->dictionary('8.1'));
        self::assertFalse($iso->has('Enforce'));
        self::assertTrue($adobe->has('Enforce'));
    }

    public function testRootFolderDoesNotRequireConditionalChildOrSiblingPointers(): void
    {
        $folder = new FolderDictionaryTable8_6c(new IDEntry(new IntegerType(0)), new NameEntry(new LiteralString('/')));
        $folder->validate();
        $definition = $folder->definition();
        foreach (['Parent', 'Child', 'Next'] as $key) {
            self::assertTrue($definition->entry($key)->requirement->required);
            self::assertTrue($definition->entry($key)->requirement->conditional);
            self::assertFalse($definition->entry($key)->requirement->unconditional());
            self::assertTrue($definition->entry($key)->type->indirect);
        }
        $child = $folder->with(new ParentEntry(new IndirectReference(new DictionaryType())));
        self::assertFalse($folder->has('Parent'));
        self::assertTrue($child->has('Parent'));
        self::assertStringContainsString('/ID 0', $folder->toPdf(new SerializationContext()));
    }

    public function testFolderUnconditionalRequirementsAreCheckedAtSerialization(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('/Name');
        (new FolderDictionaryTable8_6c(new IDEntry(new IntegerType(1))))->toPdf(new SerializationContext());
    }

    public function testGenericEntryCannotBypassAnExplicitIndirectRequirement(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('indirect reference');
        new FolderDictionaryTable8_6c(new Entry(new NameType('Parent'), new DictionaryType()));
    }

    public function testFixedNamesRemainPdfNamesAndRejectUnrelatedDictionaryTypes(): void
    {
        new TypeEntry(new NameType('Folder'));
        new SubtypeEntry(new NameType('Cloud'));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('/Cloud');
        new SubtypeEntry(new NameType('Wrong'));
    }

    public function testAnEnforceArrayContainsNamesRatherThanArbitraryObjects(): void
    {
        new EnforceEntry(new ArrayType(new NameType('PrintScaling')));
        $this->expectException(\InvalidArgumentException::class);
        new EnforceEntry(new ArrayType(new LiteralString('PrintScaling')));
    }

    public function testRichMediaArrayReferencesApplyToMembersRatherThanTheArray(): void
    {
        $class = $this->classForTable('9.51', 'definition_class');
        $constraint = (new $class())->entry('Configurations')->type;
        self::assertFalse($constraint->indirect);
        self::assertTrue($constraint->indirectArrayElements);
        $constraint->validate(new ArrayType(new IndirectReference(new DictionaryType())));
        $constraint->validate(new IndirectReference(new ArrayType(new IndirectReference(new DictionaryType()))));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('indirect references as its elements');
        $constraint->validate(new IndirectReference(new ArrayType(new DictionaryType())));
    }

    public function testRichMediaArrayReferenceTargetsAreDictionaries(): void
    {
        $class = $this->classForTable('9.51a', 'definition_class');
        $constraint = (new $class())->entry('Instances')->type;
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('array member type');
        $constraint->validate(new ArrayType(new IndirectReference(new LiteralString('not an instance'))));
    }

    public function testPointDataAcceptsBothDocumentedFormsAndChecksArrayMembers(): void
    {
        foreach (['4.39', '4.45', '8.109'] as $identifier) {
            $class = $this->classForTable($identifier, 'definition_class');
            $constraint = (new $class())->entry('PtData')->type;
            self::assertSame('dictionary or array of dictionaries', $constraint->specificationType);
            $constraint->validate(new DictionaryType());
            $constraint->validate(new ArrayType(new DictionaryType(), new IndirectReference(new DictionaryType())));
            try {
                $constraint->validate(new ArrayType(new LiteralString('not a point dictionary')));
                self::fail('Point data accepted an array of non-dictionary members.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('array member type', $error->getMessage());
            }
        }
    }

    public function testProseSpecializationsDiscriminateTheActualDictionarySubtype(): void
    {
        foreach (['8.111a' => 'GEO', '9.39b' => 'LD3', '9.39c' => 'PD3', '9.39d' => 'AD3', '9.39e' => 'RD3', '9.39f' => '3DC'] as $identifier => $name) {
            $class = $this->classForTable($identifier, 'definition_class');
            $definition = new $class();
            self::assertSame($name, $definition->entry('Subtype')->type->fixedName);
            self::assertTrue($definition->entry('Subtype')->requirement->unconditional());
            $definition->entry('Subtype')->type->validate(new NameType($name));
            try {
                $definition->entry('Subtype')->type->validate(new NameType('DifferentSubtype'));
                self::fail('A specialized dictionary accepted a different subtype.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('/' . $name, $error->getMessage());
            }
        }
    }

    public function testRichMediaCommandArgumentsUseTheDocumentedScalarDomain(): void
    {
        $class = $this->classForTable('8.48b', 'definition_class');
        $definition = new $class();
        $constraint = $definition->entry('A')->type;
        $constraint->validate(new LiteralString('argument'));
        $constraint->validate(new IntegerType(4));
        $constraint->validate(new NumericType(1.25));
        $constraint->validate(new BooleanType(true));
        $constraint->validate(new ArrayType(new LiteralString('mixed'), new IntegerType(4), new NumericType(1.25), new BooleanType(true)));
        foreach ([new NullType(), new DictionaryType(), new ArrayType(new DictionaryType()), new ArrayType(new NullType()), new ArrayType(new ArrayType()), new IndirectReference(new StreamObject('not a command argument'))] as $invalid) {
            try {
                $constraint->validate($invalid);
                self::fail('RichMedia command accepted an object outside its documented scalar domain.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('Expected PDF type', $error->getMessage());
            }
        }
    }

    public function testAdobeExtensionDeclarationsApplyTheInheritedIsoDirectnessRule(): void
    {
        foreach (['3.25' => ['Extensions'], '3.25a' => ['Type', 'Prefix name'], '3.25b' => ['Type', 'BaseVersion', 'ExtensionLevel']] as $identifier => $keys) {
            $class = $this->classForTable($identifier, 'definition_class');
            $definition = new $class();
            foreach ($keys as $key) {
                $entry = $key === 'Prefix name' ? $definition->variableEntry($key) : $definition->entry($key);
                self::assertTrue($entry->type->direct);
                self::assertFalse($entry->type->indirect);
                self::assertSame('resources/reference-documents/PDF32000_2008.pdf', $entry->correction?->evidenceUrl);
                self::assertStringContainsString('7.12.1', $entry->correction?->evidenceLocator ?? '');
                try {
                    $entry->type->validate(new IndirectReference($this->sampleValue($entry)));
                    self::fail('An extension declaration accepted an indirect object.');
                } catch (\InvalidArgumentException $error) {
                    self::assertStringContainsString('direct object', $error->getMessage());
                }
            }
        }
    }

    public function testGenericDeveloperPrefixesCannotBypassDirectnessOrDictionaryTypes(): void
    {
        $class = $this->classForTable('3.25a', 'class');
        $extensions = new $class();
        $populated = $extensions->with(new Entry(new NameType('ADBE'), new DictionaryType()));
        self::assertFalse($extensions->has('ADBE'));
        self::assertTrue($populated->has('ADBE'));
        $populated->validate();
        foreach ([new LiteralString('not a developer dictionary'), new IndirectReference(new DictionaryType())] as $invalid) {
            try {
                $unexpected = $extensions->with(new Entry(new NameType('ADBE'), $invalid));
                self::fail('An arbitrary entry bypassed the developer-prefix dictionary rules.');
            } catch (\InvalidArgumentException) {
                self::assertFalse($extensions->has('ADBE'));
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        new $class(new Entry(new NameType('ADBE'), new IndirectReference(new DictionaryType())));
    }

    public function testDeveloperDeclarationDoesNotHideReferencesInUnknownNestedEntries(): void
    {
        $class = $this->classForTable('3.25b', 'class');
        $developer = new $class();
        $invalid = new Entry(new NameType('VendorData'), new ArrayType(new IndirectReference(new IntegerType(1))));
        try {
            $unexpected = $developer->with($invalid);
            self::fail('An unknown nested entry bypassed declaration directness.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('direct objects', $error->getMessage());
            self::assertFalse($developer->has('VendorData'));
        }
        $this->expectException(\InvalidArgumentException::class);
        new $class($invalid);
    }

    public function testExtensionsRequireAPrefixWhenTheObjectIsSerialized(): void
    {
        $class = $this->classForTable('3.25a', 'class');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one developer prefix');
        (new $class(new Entry(new NameType('Type'), new NameType('Extensions'))))->toPdf(new SerializationContext());
    }

    public function testThumbnailStreamsRequireAReferenceWithTheCorrectTarget(): void
    {
        $class = $this->classForTable('3.41', 'definition_class');
        $constraint = (new $class())->entry('Thumb')->type;
        $constraint->validate(new IndirectReference(new StreamObject('thumbnail bytes')));
        $this->expectException(\InvalidArgumentException::class);
        $constraint->validate(new StreamObject('thumbnail bytes'));
    }

    public function testSourceTypoCorrectionsAreExplicitAndKeepThePrintedType(): void
    {
        $definition = new RichMediaConfigurationDictionaryTable9_51aDefinition();
        $name = $definition->entry('Name');
        self::assertSame('text string', $name->type->specificationType);
        self::assertSame('rich-media-configuration-name-text-string', $name->correction?->id);
        self::assertStringContainsString('PDF page 88', $name->correction?->evidenceLocator ?? '');
        self::assertStringContainsString('PDF page 95', $name->correction?->evidenceLocator ?? '');
        foreach ($this->inventory('dictionaries.json') as $table) {
            if ($table['id'] === '9.51a') {
                foreach ($table['entries'] as $entry) {
                    if ($entry['name'] === 'Name') {
                        self::assertSame('text tree', $entry['source_constraints']['type']);
                        return;
                    }
                }
            }
        }
        self::fail('Source table 9.51a /Name is absent.');
    }

    public function testConditionalEncryptionEntriesAndArcEndpointsStayConditional(): void
    {
        $encryptionClass = $this->classForTable('3.19', 'definition_class');
        $encryption = new $encryptionClass();
        foreach (['OE', 'UE', 'Perms'] as $key) {
            self::assertTrue($encryption->entry($key)->requirement->required);
            self::assertTrue($encryption->entry($key)->requirement->conditional);
            self::assertFalse($encryption->entry($key)->requirement->unconditional());
        }
        $radialClass = $this->classForTable('9.39e', 'definition_class');
        $radial = new $radialClass();
        foreach (['A3', 'A4'] as $key) {
            self::assertTrue($radial->entry($key)->requirement->conditional);
            self::assertStringContainsString('Required for arcs only', $radial->entry($key)->requirement->wording ?? '');
        }
    }

    public function testRepeatedMeasurementArrayIsRepresentedOnceWithoutLosingItsSource(): void
    {
        $definition = new ThreeDViewDictionaryTable9_39Definition();
        self::assertSame(57, $definition->entry('MA')->source->pdfPage);
        $occurrences = [];
        foreach ($this->inventory('dictionaries.json') as $table) {
            if ($table['id'] === '9.39') {
                foreach ($table['entries'] as $entry) {
                    if ($entry['name'] === 'MA') {
                        $occurrences = $entry['source_occurrences'];
                    }
                }
            }
        }
        self::assertCount(2, $occurrences);
        self::assertSame($occurrences[0], $occurrences[1]);
        self::assertSame('304', $definition->entry('IN')->source->table);
    }

    public function testIndependentInventoryCoversEveryPrintedTableAndImplementationNote(): void
    {
        $coverage = $this->inventory('coverage.json');
        $metadata = $this->inventory('metadata.json');
        self::assertSame(66, $coverage['tableInventory']['distinctTables']);
        self::assertSame(61, $coverage['tableInventory']['dictionaryTables']);
        self::assertCount(4, $coverage['valueTables']);
        self::assertCount(1, $coverage['xmlAttributeTables']);
        $allTables = array_merge($coverage['tableInventory']['modifiedTables'], $coverage['tableInventory']['newIdentifiersInSupplement']);
        self::assertCount(66, array_unique($allTables));
        $nonDictionaryTables = array_merge(array_column($coverage['valueTables'], 'table'), array_column($coverage['xmlAttributeTables'], 'table'));
        $expectedDictionaries = array_values(array_diff($allTables, $nonDictionaryTables));
        $actualDictionaries = array_column($this->inventory('classes.json'), 'table');
        sort($expectedDictionaries, SORT_STRING);
        sort($actualDictionaries, SORT_STRING);
        self::assertSame($expectedDictionaries, $actualDictionaries);
        $expectedNotes = array_map(strval(...), array_values(array_diff(range(1, 188), [6])));
        foreach (range(1, 20) as $identifier) {
            $expectedNotes[] = 'E-' . $identifier;
        }
        $actualNotes = array_column($coverage['implementationNotes'], 'id');
        sort($expectedNotes, SORT_STRING);
        sort($actualNotes, SORT_STRING);
        self::assertSame($expectedNotes, $actualNotes);
        foreach ($coverage['implementationNotes'] as $note) {
            self::assertSame($metadata['document'], $note['source']['filename']);
            self::assertSame($metadata['document_sha256'], $note['source']['sha256']);
            self::assertNotEmpty($note['textAsExtracted']);
            foreach ($note['source']['pdfPages'] as $page) {
                self::assertGreaterThanOrEqual(106, $page);
                self::assertLessThanOrEqual(132, $page);
            }
        }
    }

    /** @return array<mixed> */
    private function inventory(string $file): array
    {
        $json = file_get_contents(dirname(__DIR__) . '/resources/adobe-supplement-iso32000/' . $file);
        self::assertIsString($json);
        $inventory = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($inventory);
        return $inventory;
    }

    /** @return class-string<SchemaDictionary>|class-string<TableDefinition> */
    private function classForTable(string $identifier, string $property): string
    {
        foreach ($this->inventory('classes.json') as $table) {
            if ($table['table'] === $identifier) {
                return $table[$property];
            }
        }
        throw new \LogicException('Missing Adobe table ' . $identifier);
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
            default => throw new \LogicException('Missing representative PDF object type ' . $class),
        };
        return $definition->type->indirect || $value instanceof StreamObject ? new IndirectReference($value) : $value;
    }
}
