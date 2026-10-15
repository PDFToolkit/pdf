<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Serialization\SerializationContext;
use PDF\Specification\Catalogue;
use PDF\Specification\Constraint\ConditionalEntries;
use PDF\Specification\Constraint\DictionaryValues;
use PDF\Specification\Constraint\NumberArray;
use PDF\Specification\Constraint\NumberEntry;
use PDF\Specification\Definition\ImageDictionaryTable89Definition;
use PDF\Specification\Definition\Type0FunctionDictionaryTable39Definition;
use PDF\Specification\Iso32000_2\Catalogue as Pdf20Catalogue;
use PDF\Specification\Iso32000_2\Definition\ImageDictionaryTable87Definition;
use PDF\Specification\SchemaDictionary;
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
use PDF\Syntax\Object\StringObject\LiteralString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SpecificationSemanticsTest extends TestCase
{
    public function testEveryReviewedRuleIsGeneratedWithItsOwnEditionSourceAndPhysicalPage(): void
    {
        foreach (['iso32000-1' => 'iso32000-1:2008', 'iso32000-2-2020' => 'iso32000-2:2020'] as $directory => $edition) {
            $rules = json_decode(file_get_contents(dirname(__DIR__) . '/resources/' . $directory . '/semantic-rules.json'), true, flags: JSON_THROW_ON_ERROR)['rules'];
            $catalogue = $directory === 'iso32000-1' ? $this->pdf17Catalogue() : new Pdf20Catalogue();
            $identifiers = [];
            foreach ($rules as $rule) {
                self::assertArrayNotHasKey($rule['id'], $identifiers);
                $identifiers[$rule['id']] = true;
                $found = false;
                foreach ($catalogue->dictionary($rule['table'])->semanticRules() as $object) {
                    if ($object->id !== $rule['id']) {
                        continue;
                    }
                    self::assertSame($edition, $object->source->edition->identifier);
                    self::assertSame($rule['table'], $object->source->table);
                    self::assertSame($rule['physical_pdf_page'], $object->source->pdfPage);
                    $found = true;
                }
                self::assertTrue($found, $rule['id']);
            }
            self::assertCount($directory === 'iso32000-1' ? 94 : 95, $identifiers);
        }
    }

    #[DataProvider('invalidPdf20Relations')]
    public function testSourceDefinedRelationsRejectInvalidObjects(string $table, array $entries, string $diagnostic): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($diagnostic);
        $this->validateRelations((new Pdf20Catalogue())->dictionary($table), $this->dictionary($entries));
    }

    public static function invalidPdf20Relations(): iterable
    {
        yield 'negative stream length' => ['5', ['Length' => -1], '/Length'];
        yield 'filter positional arity' => ['5', ['Filter' => ['/FlateDecode', '/ASCII85Decode'], 'DecodeParms' => [new DictionaryType()]], 'positional slot'];
        yield 'parameters need filter' => ['5', ['DecodeParms' => new DictionaryType()], 'requires a filter'];
        yield 'invalid parameter slot' => ['5', ['Filter' => ['/FlateDecode'], 'DecodeParms' => [123]], 'dictionary or null'];
        yield 'predictor reserved code' => ['8', ['Predictor' => 9], '/Predictor'];
        yield 'predictor absent' => ['8', ['Columns' => 10], '/Columns'];
        yield 'invalid predictor samples' => ['8', ['Predictor' => 12, 'BitsPerComponent' => 3], '/BitsPerComponent'];
        yield 'fax width' => ['11', ['Columns' => 0], '/Columns'];
        yield 'xref overlapping' => ['17', ['Size' => 9, 'W' => [1, 4, 2], 'Index' => [0, 4, 3, 4]], 'nonoverlapping'];
        yield 'xref beyond size' => ['17', ['Size' => 9, 'W' => [1, 4, 2], 'Index' => [8, 2]], '/Size'];
        yield 'xref absent second field' => ['17', ['W' => [1, 0, 2]], 'second /W'];
        yield 'xref EC3 external source' => ['17', ['F' => new LiteralString('payload.bin')], 'forbids /F'];
        yield 'xref EC3 associated file' => ['17', ['AF' => [new DictionaryType()]], 'forbids /AF'];
        yield 'xref EC3 deep indirect parameter' => ['17', ['Filter' => '/FlateDecode', 'DecodeParms' => self::parameters(new IndirectReference(new IntegerType(2)))], 'shall be direct'];
        yield 'sampled range mandatory' => ['39', ['FunctionType' => 0, 'Domain' => [0, 1], 'Size' => [2], 'BitsPerSample' => 8], '/Range is required'];
        yield 'function reversed input' => ['38', ['FunctionType' => 2, 'Domain' => [2, 1]], 'reversed interval'];
        yield 'sample dimensions' => ['39', ['FunctionType' => 0, 'Domain' => [0, 1, 0, 1], 'Range' => [0, 1], 'Size' => [2], 'BitsPerSample' => 8], '/Size dimensionality'];
        yield 'interpolation order' => ['39', ['Order' => 2], '/Order'];
        yield 'exponential output dimensions' => ['40', ['FunctionType' => 2, 'Domain' => [0, 1], 'C0' => [0, 0], 'C1' => [1]], 'output dimensions'];
        yield 'stitching bounds count' => ['41', ['FunctionType' => 3, 'Domain' => [0, 1], 'Functions' => [new DictionaryType(), new DictionaryType()], 'Bounds' => [], 'Encode' => [0, 1, 0, 1]], 'stitching dimensions'];
        yield 'stitching bounds domain' => ['41', ['FunctionType' => 3, 'Domain' => [0, 1], 'Functions' => [new DictionaryType(), new DictionaryType()], 'Bounds' => [2], 'Encode' => [0, 1, 0, 1]], 'outside /Domain'];
        yield 'whitepoint normalization' => ['62', ['WhitePoint' => [0.95, 2, 1.09]], '/WhitePoint'];
        yield 'blackpoint negativity' => ['62', ['BlackPoint' => [-0.1, 0, 0]], '/BlackPoint'];
        yield 'gamma positivity' => ['63', ['Gamma' => [1, 0, 1]], '/Gamma'];
        yield 'icc component count' => ['65', ['N' => 2], '/N'];
        yield 'icc range dimensions' => ['65', ['N' => 3, 'Range' => [0, 1]], 'two numbers per component'];
        yield 'icc alternate agreement' => ['65', ['N' => 4, 'Alternate' => '/DeviceRGB'], 'component count'];
        yield 'tiling step nonzero' => ['74', ['XStep' => 0], '/XStep'];
        yield 'tiling type reserved' => ['74', ['TilingType' => 4], '/TilingType'];
        yield 'mesh coordinate width' => ['81', ['BitsPerCoordinate' => 3], '/BitsPerCoordinate'];
        yield 'image missing colour' => ['87', ['Width' => 1, 'Height' => 1, 'BitsPerComponent' => 8], '/ColorSpace'];
        yield 'image mask forbids colour' => ['87', ['ImageMask' => true, 'ColorSpace' => '/DeviceGray'], 'image mask forbids'];
        yield 'image mask decode' => ['87', ['ImageMask' => true, 'Decode' => [0, 0]], '/Decode'];
        yield 'image codec samples' => ['87', ['Filter' => '/DCTDecode', 'ColorSpace' => '/DeviceRGB', 'BitsPerComponent' => 1], 'inconsistent'];
        yield 'image components' => ['87', ['ColorSpace' => '/DeviceRGB', 'BitsPerComponent' => 8, 'Decode' => [0, 1]], '/Decode length'];
        yield 'image pattern prohibition' => ['87', ['ColorSpace' => '/Pattern', 'BitsPerComponent' => 8], 'Pattern'];
        yield 'leader extension needs leader' => ['178', ['LLE' => 1], '/LL'];
        yield 'annotation colour components' => ['178', ['IC' => [0, 1]], '/IC'];
        yield 'polygon missing geometry' => ['181', [], '/Vertices or /Path'];
        yield 'polygon contradictory geometry' => ['181', ['Vertices' => [0, 0, 1, 1], 'Path' => [[0, 0], [1, 1]]], 'forbids /Vertices'];
        yield 'polygon first operator cubic' => ['181', ['Path' => [[0, 0, 1, 1, 2, 2]]], 'array length'];
        yield 'quadpoint packing' => ['182', ['QuadPoints' => [0, 0, 1, 1]], 'array length'];
        yield 'choice export pair' => ['234', ['Opt' => [[new LiteralString('export')]]], 'two-string'];
        yield 'choice sorted selections' => ['234', ['Opt' => [new LiteralString('a'), new LiteralString('b')], 'I' => [1, 0]], 'strictly increasing'];
        yield 'choice selection bounds' => ['234', ['Opt' => [new LiteralString('a')], 'I' => [1]], '/Opt item'];
    }

    public function testFilterNullSlotsEmptyFilterAndIndirectParameterTargetsArePreserved(): void
    {
        $definition = (new Pdf20Catalogue())->dictionary('5');
        $this->validateRelations($definition, $this->dictionary(['Length' => 0, 'Filter' => []]));
        $this->validateRelations($definition, $this->dictionary(['Filter' => ['/ASCII85Decode', '/FlateDecode'], 'DecodeParms' => [new NullType(), new IndirectReference(new DictionaryType())]]));
        $this->validateRelations($definition, $this->dictionary(['FFilter' => '/FlateDecode', 'FDecodeParms' => new IndirectReference(new DictionaryType())]));
        self::assertTrue(true);
    }

    public function testSchemaValidationAndSerializationExecuteInheritedRelationsWithoutMutatingBuilders(): void
    {
        $definition = new Type0FunctionDictionaryTable39Definition();
        $schema = new class ($definition, ...iterator_to_array($this->dictionary(['FunctionType' => 0, 'Domain' => [0, 1], 'Range' => [0, 1], 'Size' => [2], 'BitsPerSample' => 8])->entries())) extends SchemaDictionary {};
        $schema->validate();
        self::assertStringContainsString('/BitsPerSample 8', $schema->toPdf(new SerializationContext()));
        $invalid = $schema->with(new Entry(new NameType('Size'), new ArrayType(new IntegerType(2), new IntegerType(2))));
        self::assertCount(1, $schema->get('Size'));
        $this->expectException(\InvalidArgumentException::class);
        $invalid->toPdf(new SerializationContext());
    }

    public function testJpxSampleDepthIsIgnoredAndMaskDecodeMayReverseTheInterval(): void
    {
        foreach ([new ImageDictionaryTable89Definition(), new ImageDictionaryTable87Definition()] as $definition) {
            $this->validateRelations($definition, $this->dictionary(['Filter' => '/JPXDecode', 'BitsPerComponent' => 17]));
            $this->validateRelations($definition, $this->dictionary(['ImageMask' => true, 'Decode' => [1, 0]]));
            $this->validateRelations($definition, $this->dictionary(['ColorSpace' => '/DeviceGray', 'BitsPerComponent' => 2, 'Decode' => [1, 0]]));
        }
        self::assertTrue(true);
    }

    public function testValueObjectsAreComposableAndNullActsAsAbsenceInRelations(): void
    {
        $dictionary = $this->dictionary(['Trigger' => new NullType()]);
        self::assertNull((new DictionaryValues())->value($dictionary, 'Trigger'));
        (new ConditionalEntries('Trigger', required: ['Required']))->validate($dictionary);
        (new ConditionalEntries('Trigger', prohibited: ['Forbidden'], whenAbsent: true))->validate($dictionary);
        (new NumberEntry('Missing', minimum: 0))->validate($dictionary);
        (new NumberArray('Missing', lengths: [6]))->validate($dictionary);
        self::assertTrue(true);
    }

    private static function parameters(BasicType $value): DictionaryType
    {
        return new DictionaryType(new Entry(new NameType('Predictor'), $value));
    }

    private function validateRelations(TableDefinition $definition, DictionaryType $dictionary): void
    {
        foreach ($definition->semanticRules() as $rule) {
            $rule->validate($dictionary);
        }
    }

    /** @param array<string, mixed> $entries */
    private function dictionary(array $entries): DictionaryType
    {
        $objects = [];
        foreach ($entries as $key => $value) {
            $objects[] = new Entry(new NameType($key), $this->value($value));
        }
        return new DictionaryType(...$objects);
    }

    private function value(mixed $value): BasicType
    {
        return match (true) {
            $value instanceof BasicType => $value,
            is_bool($value) => new BooleanType($value),
            is_int($value) => new IntegerType($value),
            is_float($value) => new NumericType($value),
            is_string($value) && str_starts_with($value, '/') => new NameType(substr($value, 1)),
            is_array($value) => new ArrayType(...array_map($this->value(...), $value)),
            default => throw new \InvalidArgumentException('Unsupported test vector.'),
        };
    }

    private function pdf17Catalogue(): Catalogue
    {
        $manifest = json_decode(file_get_contents(dirname(__DIR__) . '/resources/iso32000-1/classes.json'), true, flags: JSON_THROW_ON_ERROR);
        return new Catalogue(...array_map(fn (array $table): TableDefinition => new ($table['definition_class'])(), $manifest));
    }
}
