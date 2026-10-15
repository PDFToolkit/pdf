<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Serialization\SerializationContext;
use PDF\Specification\Adobe\Definition\ViewerPreferencesDictionaryTable8_1Definition;
use PDF\Specification\Catalogue;
use PDF\Specification\Definition\OPIVersionDictionaryTable368Definition;
use PDF\Specification\Definition\PageTreeNodeTable29Definition;
use PDF\Specification\Definition\ViewerPreferencesDictionaryTable150Definition;
use PDF\Specification\EntryDefinition;
use PDF\Specification\ErrataCollection;
use PDF\Specification\ErrataReviewStatus;
use PDF\Specification\Iso32000_2\Catalogue as Pdf20Catalogue;
use PDF\Specification\Iso32000_2\Definition\CatalogDictionaryTable29Definition;
use PDF\Specification\Iso32000_2\Definition\FileSpecificationDictionaryTable43Definition;
use PDF\Specification\Iso32000_2\Dictionary\AssociatedFilesPropertyListTable409a;
use PDF\Specification\Iso32000_2\Dictionary\AssociatedFilesPropertyListTable409a\MCAFEntry;
use PDF\Specification\Iso32000_2\Dictionary\CatalogDictionaryTable29;
use PDF\Specification\Iso32000_2\Dictionary\CatalogDictionaryTable29\ExtensionsEntry;
use PDF\Specification\Iso32000_2\Dictionary\CatalogDictionaryTable29\PagesEntry;
use PDF\Specification\Iso32000_2\Dictionary\CatalogDictionaryTable29\TypeEntry;
use PDF\Specification\Iso32000_2\Dictionary\NamespaceDictionaryTable356;
use PDF\Specification\Iso32000_2\Dictionary\NamespaceDictionaryTable356\NSEntry;
use PDF\Specification\Iso32000_2\Dictionary\ExtensionsDictionaryTable48\NamedEntry1 as ExtensionPrefix;
use PDF\Specification\Iso32000_2\Dictionary\OPIVersionDictionaryTable405\NamedEntry1 as OpiVersion;
use PDF\Specification\ObjectTypeConstraint;
use PDF\Specification\NameAlternatives;
use PDF\Specification\Requirement;
use PDF\Specification\SchemaDictionary;
use PDF\Specification\SchemaEntry;
use PDF\Specification\SourceEdition;
use PDF\Specification\SourceReference;
use PDF\Specification\TableDefinition;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringObject\LiteralString;
use PDF\Syntax\Object\StringType;
use PHPUnit\Framework\TestCase;

final class Iso32000_2SpecificationTest extends TestCase
{
    public function testEveryEntryConstructsItsDeclaredDomainWithPhysicalSourceProvenance(): void
    {
        $manifest = $this->inventory('classes.json');
        $metadata = $this->inventory('metadata.json');
        $entries = 0;
        foreach ($manifest as $table) {
            $dictionary = new ($table['class'])();
            $definition = new ($table['definition_class'])();
            self::assertInstanceOf(SchemaDictionary::class, $dictionary);
            self::assertInstanceOf(TableDefinition::class, $definition);
            self::assertSame($table['table'], $definition->source->table);
            self::assertSame('iso32000-2:2020', $definition->source->edition->identifier);
            self::assertSame('iso32000-2', $definition->source->edition->family);
            self::assertSame('2.0', $definition->source->edition->specifiedPdfVersion);
            self::assertSame(3, $definition->source->edition->corrections?->number);
            self::assertSame('2026-06-01', $definition->source->edition->corrections->publicationDate);
            self::assertSame($metadata['source']['sha256'], $definition->source->documentSha256);
            foreach ($table['entries'] as $entry) {
                $entryDefinition = $entry['variable'] ? $definition->variableEntry($entry['name']) : $definition->entry($entry['name']);
                $key = $table['table'] === '405' ? '1.3' : 'ApplicationKey';
                $object = $entry['variable'] ? new ($entry['class'])(new NameType($key), $this->sampleValue($entryDefinition)) : new ($entry['class'])($this->sampleValue($entryDefinition));
                self::assertInstanceOf(SchemaEntry::class, $object);
                self::assertSame($entry['variable'] ? $key : $entry['name'], $object->key()->value);
                self::assertSame($table['table'], $object->definition()->source->table);
                self::assertGreaterThanOrEqual(47, $object->definition()->source->pdfPage);
                self::assertLessThanOrEqual(1022, $object->definition()->source->pdfPage);
                $entries++;
            }
        }
        self::assertCount(306, $manifest);
        self::assertSame(1711, $entries);
        self::assertSame($entries, $metadata['dictionary_catalogue']['entry_count']);
        self::assertTrue($metadata['source']['public_copy_included']);
        $originalSource = dirname(__DIR__) . '/' . $metadata['source']['path'];
        self::assertFileExists($originalSource);
        self::assertSame($metadata['source']['sha256'], hash_file('sha256', $originalSource));
        self::assertSame($metadata['source']['bytes'], filesize($originalSource));
        self::assertSame('PDF Association', $metadata['source']['provider']);
        self::assertSame('https://www.pdfa.org/sponsored-standards/', $metadata['source']['provider_url']);
        self::assertSame('implemented-base-inventory', $this->inventory('coverage.json')['complete_dictionary_catalogue']);
        self::assertFalse($this->inventory('coverage.json')['complete_standard_conformance']);
    }

    public function testExplicitCatalogueIncludesContinuationsRepairedGridsAndAppendixAdditions(): void
    {
        $catalogue = new Pdf20Catalogue();
        self::assertCount(306, iterator_to_array($catalogue->definitions()));
        self::assertCount(440, $this->inventory('tables.json'));
        foreach (['5' => 7, '29' => 32, '45' => 5, '48' => 1, '110' => 13, '159' => 12, '178' => 13, '250' => 4, '262' => 6, '280' => 3, '315' => 16, '383' => 4, '125a' => 5] as $table => $count) {
            $ownEntries = array_filter(iterator_to_array($catalogue->dictionary((string) $table)->entries()), fn (EntryDefinition $entry): bool => $entry->source->table === (string) $table);
            self::assertCount($count, $ownEntries, 'Table ' . $table);
        }
        // Type 1 halftones are dictionaries, whereas sampled halftones are streams.
        self::assertFalse($catalogue->dictionary('128')->has('Length'));
        self::assertTrue($catalogue->dictionary('129')->has('Length'));
        self::assertSame('7.12.2', $catalogue->dictionary('48')->source->clause);
        self::assertSame('9.10.3', $catalogue->dictionary('125a')->source->clause);
        self::assertSame('14.8.5.6', $catalogue->dictionary('383')->source->clause);
        self::assertSame('VRI', $catalogue->dictionary('262')->entry('Type')->type->fixedName);
        self::assertSame('Line', $catalogue->dictionary('178')->entry('Subtype')->type->fixedName);
        self::assertSame('CMap', $catalogue->dictionary('125a')->entry('Type')->type->fixedName);
        self::assertSame(462, $catalogue->dictionary('125a')->entry('Type')->errata?->issueNumber);
        self::assertSame(ErrataReviewStatus::Completed, $catalogue->dictionary('125a')->entry('Type')->errata->reviewStatus);
        $this->expectException(\OutOfBoundsException::class);
        $catalogue->dictionary('117'); // Explicitly empty numbering placeholder, not a dictionary.
    }

    public function testCaseSensitiveNamesDoNotCollideInPhpOrOnWindows(): void
    {
        $catalogue = new Pdf20Catalogue();
        foreach (['OP', 'op', 'CA', 'ca'] as $name) {
            self::assertSame($name, $catalogue->dictionary('57')->entry($name)->name);
        }
        self::assertSame('Checked', $catalogue->dictionary('383')->entry('Checked')->name);
        self::assertSame('checked', $catalogue->dictionary('383')->entry('checked')->name);
        $classes = [];
        foreach ($this->inventory('classes.json') as $table) {
            foreach ($table['entries'] as $entry) {
                $class = strtolower($entry['class']);
                self::assertArrayNotHasKey($class, $classes);
                $classes[$class] = true;
            }
        }
    }

    public function testCrossReferenceStreamRequiresDirectArrayMembers(): void
    {
        $definition = (new Pdf20Catalogue())->dictionary('17');
        self::assertSame('iso32000-2', $definition->entry('Length')->source->edition->family);
        self::assertSame('5', $definition->entry('Length')->source->table);
        self::assertSame('15', $definition->entry('Root')->source->table);
        $definition->entry('W')->type->validate(new ArrayType(new IntegerType(1), new IntegerType(2), new IntegerType(1)));
        $this->expectException(\InvalidArgumentException::class);
        $definition->entry('W')->type->validate(new ArrayType(new IndirectReference(new IntegerType(1))));
    }

    public function testExtensionPrefixIsAnExplicitVariableDirectObject(): void
    {
        $entry = new ExtensionPrefix(new NameType('ADBE'), new DictionaryType());
        self::assertSame('ADBE', $entry->key()->value);
        self::assertTrue($entry->definition()->variable);
        $this->expectException(\InvalidArgumentException::class);
        $entry->definition()->type->validate(new IndirectReference(new DictionaryType()));
    }

    public function testPdf20OpiVersionKeysUseTheirOwnTableIdentity(): void
    {
        self::assertSame('1.3', (new OpiVersion(new NameType('1.3'), new DictionaryType()))->key()->value);
        self::assertSame('2.0', (new OpiVersion(new NameType('2.0'), new DictionaryType()))->key()->value);
        $this->expectException(\InvalidArgumentException::class);
        new OpiVersion(new NameType('Custom'), new DictionaryType());
    }

    public function testConditionalAndInheritableRequirementsArePreservedWithoutBecomingUnconditional(): void
    {
        $catalogue = new Pdf20Catalogue();
        self::assertFalse($catalogue->dictionary('159')->entry('Parent')->requirement->unconditional());
        self::assertTrue($catalogue->dictionary('159')->entry('Parent')->requirement->conditional);
        self::assertTrue($catalogue->dictionary('226')->entry('FT')->requirement->inheritable);
        self::assertFalse($catalogue->dictionary('226')->entry('FT')->requirement->unconditional());
        self::assertTrue($catalogue->dictionary('262')->entry('CRL')->requirement->conditional);
        self::assertSame('byte string', $catalogue->dictionary('382')->entry('ContinuedFrom')->type->specificationType);
        self::assertSame('name or array', $catalogue->dictionary('57')->entry('BM')->type->specificationType);
        self::assertTrue($catalogue->dictionary('110')->entry('FontDescriptor')->requirement->conditional);
        self::assertTrue($catalogue->dictionary('109')->entry('Name')->requirement->conditional);
    }

    public function testReviewedAlternativesAcceptAllSourceBranchesWithoutInventingASingleton(): void
    {
        $catalogue = new Pdf20Catalogue();
        $cases = [
            ['31', 'Type', ['Page', 'Template']],
            ['70', 'Subtype', ['DeviceN', 'NChannel']],
            ['115', 'Subtype', ['CIDFontType0', 'CIDFontType2']],
            ['125', 'Subtype', ['Type1C', 'CIDFontType0C', 'OpenType']],
            ['178', 'IT', ['LineArrow', 'LineDimension']],
            ['180', 'Subtype', ['Square', 'Circle']],
            ['181', 'Subtype', ['Polygon', 'PolyLine']],
            ['182', 'Subtype', ['Highlight', 'Underline', 'Squiggly', 'StrikeOut']],
            ['255', 'Type', ['Sig', 'DocTimeStamp']],
            ['255', 'Prop_AuthType', ['PIN', 'Password', 'Fingerprint']],
            ['276', 'S', ['JS', 'NoOp']],
            ['316', 'Subtype', ['O', 'P']],
            ['316', 'CS', ['XNF', 'ANF']],
        ];
        foreach ($cases as [$table, $key, $names]) {
            $constraint = $catalogue->dictionary($table)->entry($key)->type;
            self::assertNull($constraint->fixedName);
            self::assertNotNull($constraint->nameAlternatives);
            foreach ($names as $name) {
                $constraint->validate(new NameType($name));
                $constraint->validate(new IndirectReference(new NameType($name)));
            }
            try {
                $constraint->validate(new NameType('NotASourceAlternative'));
                self::fail('The explicitly closed source alternatives must reject other names.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        foreach ([['25', 'CFM'], ['99', 'BaseState'], ['112', 'BaseEncoding'], ['258', 'V']] as [$table, $key]) {
            self::assertNull($catalogue->dictionary($table)->entry($key)->type->fixedName);
        }
    }

    public function testPdfNameAlternativesAreCaseSensitiveAndRejectDuplicates(): void
    {
        $names = new NameAlternatives(new NameType('OP'), new NameType('op'));
        $names->validate(new NameType('OP'));
        $names->validate(new NameType('op'));
        self::assertCount(2, $names->names);
        $this->expectException(\InvalidArgumentException::class);
        new NameAlternatives(new NameType('OP'), new NameType('OP'));
    }

    public function testNamedVariadicAlternativesStillProduceAnExplicitOrderedList(): void
    {
        $names = new NameAlternatives(first: new NameType('OP'), lowercase: new NameType('op'));
        self::assertSame([0, 1], array_keys($names->names));
        self::assertSame('OP', $names->names[0]->value);
        self::assertSame('op', $names->names[1]->value);
        $names->validate(new NameType('op'));
    }

    public function testReviewedGeospatialCorrectionsKeepSourceTextAndApprovalAuthoritiesDistinct(): void
    {
        $geospatial = (new Pdf20Catalogue())->dictionary('269');
        $local = $geospatial->entry('LPTS');
        self::assertTrue($local->requirement->unconditional());
        self::assertSame(533, $local->errata?->issueNumber);
        self::assertSame(ErrataReviewStatus::Accepted, $local->errata->reviewStatus);
        self::assertStringStartsWith('(Optional;', $local->description);
        self::assertSame(284, $geospatial->entry('GPTS')->errata?->issueNumber);
        $matrix = $geospatial->entry('PCSM');
        self::assertSame(534, $matrix->errata?->issueNumber);
        self::assertSame(ErrataReviewStatus::Accepted, $matrix->errata->reviewStatus);
        self::assertSame(12, $matrix->type->arrayLength);
        self::assertSame('number', $matrix->type->arrayElementConstraint?->specificationType);
        $matrix->type->validate(new ArrayType(...array_fill(0, 12, new IntegerType(0))));
        $matrix->type->validate(new ArrayType(...array_fill(0, 12, new NumericType(0.5))));
        $review = $this->inventory('extraction-review.json');
        self::assertSame('Completed', $review['geospatial_review_replies']['358']['state']);
        $this->expectException(\InvalidArgumentException::class);
        $matrix->type->validate(new ArrayType(...array_fill(0, 11, new IntegerType(0))));
    }

    public function testSourceDescriptionsKeepTheirLanguageAndAuthoredOriginsExplicit(): void
    {
        $authored = $normative = $variables = 0;
        foreach ($this->inventory('dictionaries.json') as $table) {
            foreach ([...$table['entries'], ...$table['variable_entries']] as $entry) {
                self::assertSame('en', $entry['description_language']);
                self::assertContains($entry['description_origin'], ['project-authored', 'normative-source']);
                $authored += $entry['description_origin'] === 'project-authored' ? 1 : 0;
                $normative += $entry['description_origin'] === 'normative-source' ? 1 : 0;
            }
            $variables += count($table['variable_entries']);
        }
        self::assertSame(80, $authored);
        self::assertSame(1631, $normative);
        self::assertSame(7, $variables);
    }

    public function testRepeatedTableNumbersDoNotMixThe2008And2020Corpora(): void
    {
        $old = new PageTreeNodeTable29Definition();
        $new = new CatalogDictionaryTable29Definition();
        self::assertSame('Pages', $old->entry('Type')->type->fixedName);
        self::assertSame('Catalog', $new->entry('Type')->type->fixedName);
        self::assertSame($old, (new Catalogue($old))->dictionary('29'));
        self::assertSame($new, (new Catalogue($new))->dictionary('29'));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Different specification families');
        new Catalogue($old, $new);
    }

    public function testDistinctTableNumbersAlsoCannotSilentlyMixEditionFamilies(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Catalogue(new ViewerPreferencesDictionaryTable150Definition(), new FileSpecificationDictionaryTable43Definition());
    }

    public function testHistoricalIsoAndAdobeDefinitionsRemainCompatible(): void
    {
        $iso = new ViewerPreferencesDictionaryTable150Definition();
        $adobe = new ViewerPreferencesDictionaryTable8_1Definition();
        $catalogue = new Catalogue($iso, $adobe);
        self::assertSame($iso, $catalogue->dictionary('150'));
        self::assertSame($adobe, $catalogue->dictionary('8.1'));
        self::assertSame('iso32000-1', $adobe->source->edition->family);
        self::assertNotSame($iso->source->edition->identifier, $adobe->source->edition->identifier);
    }

    public function testBaseEditionIsIndependentOfCopyHashAndSelectedErrata(): void
    {
        $base = new SourceEdition('iso32000-2:2020', 'ISO 32000-2:2020', '2.0', 'iso32000-2');
        $corrected = new SourceEdition('iso32000-2:2020', 'ISO 32000-2:2020', '2.0', 'iso32000-2', new ErrataCollection(3, '2026-06-01'));
        $first = new SourceReference('29', '7.7.2', 113, 'ISO 32000-2:2020', str_repeat('a', 64), 'private-a.pdf', $base);
        $second = new SourceReference('29', '7.7.2', 113, 'ISO 32000-2:2020', str_repeat('b', 64), 'private-b.pdf', $corrected);
        self::assertSame($first->edition->identifier, $second->edition->identifier);
        self::assertNotSame($first->documentSha256, $second->documentSha256);
        self::assertNull($first->edition->corrections);
        self::assertSame(3, $second->edition->corrections?->number);
    }

    public function testAcceptedAndCompletedCorrectionsRetainDifferentApprovalAuthorities(): void
    {
        $definition = new CatalogDictionaryTable29Definition();
        self::assertSame(ErrataReviewStatus::Completed, $definition->entry('Extensions')->errata?->reviewStatus);
        self::assertSame(ErrataReviewStatus::Accepted, $definition->entry('DPartRoot')->errata?->reviewStatus);
        self::assertSame('ISO TC 171 SC 2 WG 8', $definition->entry('Extensions')->errata->reviewStatus->approvalAuthority());
        self::assertSame('PDF Association PDF Technical Working Group', $definition->entry('DPartRoot')->errata->reviewStatus->approvalAuthority());
        $property = (new AssociatedFilesPropertyListTable409a())->definition()->entry('MCAF');
        self::assertSame(374, $property->errata?->issueNumber);
        self::assertSame(1015, $property->source->pdfPage);
    }

    public function testCatalogUsesItsOwnTypesRequiredEntriesAndCorrectedDirectness(): void
    {
        $catalog = new CatalogDictionaryTable29(new TypeEntry(new NameType('Catalog')));
        $pages = new IndirectReference(new DictionaryType());
        $complete = $catalog->with(new PagesEntry($pages))->with(new ExtensionsEntry(new DictionaryType()));
        self::assertFalse($catalog->has('Pages'));
        self::assertSame($pages, $complete->get('Pages'));
        self::assertStringContainsString('/Type /Catalog', $complete->toPdf(new SerializationContext()));
        try {
            $catalog->validate();
            self::fail('The PDF 2.0 catalog must require its Pages entry.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('/Pages', $error->getMessage());
        }
        $this->expectException(\InvalidArgumentException::class);
        new CatalogDictionaryTable29(new Entry(new NameType('Extensions'), new IndirectReference(new DictionaryType())));
    }

    public function testGenericEntriesCannotBypassCorrectedStructureRootIndirectness(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CatalogDictionaryTable29(new Entry(new NameType('StructTreeRoot'), new DictionaryType()));
    }

    public function testFileRelationshipIsOptionalInTheSchemaAndRequiredInAssociationContext(): void
    {
        // The dictionary table's optional entry is not unconditionally required:
        // its association-context requirement belongs to native graph validation.
        $definition = new FileSpecificationDictionaryTable43Definition();
        self::assertFalse($definition->entry('AFRelationship')->requirement->unconditional());
        self::assertSame(152, $definition->entry('AFRelationship')->source->pdfPage);
        self::assertSame('2.0', $definition->entry('AFRelationship')->requirement->introducedIn);
    }

    public function testNamespaceRequiresItsIdentityWithoutFetchingTheUri(): void
    {
        $namespace = new NamespaceDictionaryTable356(new NSEntry(new LiteralString('https://example.invalid/structure')));
        self::assertStringContainsString('/NS (https://example.invalid/structure)', $namespace->toPdf(new SerializationContext()));
        $this->expectException(\InvalidArgumentException::class);
        (new NamespaceDictionaryTable356())->validate();
    }

    public function testCorrectedMcafPropertyRemainsADictionaryContainingFileSpecifications(): void
    {
        $files = new ArrayType(new DictionaryType(new Entry(new NameType('AFRelationship'), new NameType('Data'))));
        $properties = new AssociatedFilesPropertyListTable409a(new MCAFEntry($files));
        self::assertSame($files, $properties->get('MCAF'));
        self::assertStringContainsString('/MCAF [<< /AFRelationship /Data >>]', $properties->toPdf(new SerializationContext()));
        self::assertSame('array', $properties->definition()->entry('MCAF')->type->specificationType);
        $this->expectException(\InvalidArgumentException::class);
        new AssociatedFilesPropertyListTable409a(new Entry(new NameType('MCAF'), new ArrayType(new NameType('Wrong'))));
    }

    public function testTheOpi368RestrictionIsScopedToThe2008Edition(): void
    {
        $source = (new CatalogDictionaryTable29Definition())->source;
        $iso2 = new SourceReference('368', '14.8.4.7.2', 777, $source->standard, $source->documentSha256, $source->document, $source->edition);
        $definition = new EntryDefinition('Custom', new ObjectTypeConstraint('dictionary'), new Requirement(null, false, false, false, null), $iso2, 'An explicitly supplied variable definition.', true);
        $entry = new class ($definition, new DictionaryType(), new NameType('Custom')) extends SchemaEntry {};
        self::assertSame('Custom', $entry->key()->value);
        $legacy = (new OPIVersionDictionaryTable368Definition())->variableEntry('version number');
        $this->expectException(\InvalidArgumentException::class);
        new class ($legacy, new DictionaryType(), new NameType('Custom')) extends SchemaEntry {};
    }

    public function testUncorrectedAndEc3DefinitionProfilesCannotBeMixed(): void
    {
        $corrected = new CatalogDictionaryTable29Definition();
        $source = $corrected->source;
        $base = new SourceEdition('iso32000-2:2020', 'ISO 32000-2:2020', '2.0', 'iso32000-2');
        $uncorrected = new TableDefinition(new SourceReference('43', '7.11.3', 149, $source->standard, $source->documentSha256, $source->document, $base), 'An explicitly uncorrected profile.', []);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('correction profiles');
        new Catalogue($corrected, $uncorrected);
    }

    public function testExplicitEditionCannotContradictTheReferencedStandard(): void
    {
        $edition = new SourceEdition('iso32000-2:2020', 'ISO 32000-2:2020', '2.0', 'iso32000-2');
        $this->expectException(\InvalidArgumentException::class);
        new SourceReference('29', '7.7.2', 113, edition: $edition);
    }

    public function testInvalidErrataDatesAreRejectedWithoutNormalisingToAnotherDate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ErrataCollection(3, '2026-02-30');
    }

    private function sampleValue(EntryDefinition $entry): BasicType
    {
        $type = $entry->type->classes[0];
        $value = match ($type) {
            NameType::class => $entry->type->nameAlternatives?->names[0] ?? new NameType($entry->type->fixedName ?? 'Value'),
            IntegerType::class => new IntegerType(1),
            NumericType::class => new NumericType(0.5),
            BooleanType::class => new BooleanType(true),
            DictionaryType::class => new DictionaryType(),
            ArrayType::class => $entry->type->arrayLength !== null ? new ArrayType(...array_fill(0, $entry->type->arrayLength, new IntegerType(0))) : ($entry->type->specificationType === 'rectangle' ? new ArrayType(new IntegerType(0), new IntegerType(0), new IntegerType(10), new IntegerType(10)) : new ArrayType()),
            StringType::class => new LiteralString('Value'),
            StreamObject::class => new StreamObject('bytes'),
            BasicType::class => new LiteralString('Explicit application value'),
            default => throw new \LogicException('Unrepresented specification domain: ' . $type),
        };
        return $entry->type->indirect || $value instanceof StreamObject ? new IndirectReference($value) : $value;
    }

    /** @return array<mixed> */
    private function inventory(string $file): array
    {
        $bytes = file_get_contents(dirname(__DIR__) . '/resources/iso32000-2-2020/' . $file);
        self::assertIsString($bytes);
        return json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
    }
}
