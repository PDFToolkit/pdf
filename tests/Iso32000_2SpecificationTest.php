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
use PDF\Specification\ObjectTypeConstraint;
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
    public function testEveryPilotEntryConstructsItsDeclaredDomainWithPhysicalSourceProvenance(): void
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
                $entryDefinition = $definition->entry($entry['name']);
                $object = new ($entry['class'])($this->sampleValue($entryDefinition));
                self::assertInstanceOf(SchemaEntry::class, $object);
                self::assertSame($entry['name'], $object->key()->value);
                self::assertSame($table['table'], $object->definition()->source->table);
                self::assertGreaterThanOrEqual(113, $object->definition()->source->pdfPage);
                self::assertLessThanOrEqual(1015, $object->definition()->source->pdfPage);
                $entries++;
            }
        }
        self::assertCount(4, $manifest);
        self::assertSame(53, $entries);
        self::assertSame($entries, $metadata['dictionary_catalogue']['entry_count']);
        self::assertFalse($metadata['source']['public_copy_included']);
        self::assertFileDoesNotExist(dirname(__DIR__) . '/' . $metadata['source']['document']);
        self::assertSame('pending', $this->inventory('coverage.json')['complete_dictionary_catalogue']);
        self::assertFalse($this->inventory('coverage.json')['complete_standard_conformance']);
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
            NameType::class => new NameType($entry->type->fixedName ?? 'Value'),
            IntegerType::class => new IntegerType(1),
            NumericType::class => new NumericType(0.5),
            BooleanType::class => new BooleanType(true),
            DictionaryType::class => new DictionaryType(),
            ArrayType::class => new ArrayType(),
            StringType::class => new LiteralString('Value'),
            StreamObject::class => new StreamObject('bytes'),
            default => throw new \LogicException('Unrepresented pilot domain: ' . $type),
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
