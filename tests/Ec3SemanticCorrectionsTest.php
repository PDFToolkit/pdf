<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\Files\EmbeddedFileMediaType;
use PDF\Document\Files\EmbeddedFileStream;
use PDF\Serialization\SerializationContext;
use PDF\Specification\Constraint\DirectDictionaryObjects;
use PDF\Specification\Iso32000_2\Catalogue;
use PDF\Specification\Iso32000_2\Definition\Type2FunctionDictionaryTable40Definition;
use PDF\Specification\SchemaDictionary;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringObject\LiteralString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Exact EC3 source anchors are recorded in resources/iso32000-2-2020/ec3-semantic-reviews.json. */
final class Ec3SemanticCorrectionsTest extends TestCase
{
    #[DataProvider('invalidRelations')]
    public function testCorrectedRelationsRejectMalformedValues(string $table, array $entries, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $this->validate($table, $this->dictionary($entries));
    }

    public static function invalidRelations(): iterable
    {
        yield 'fractional negative domain' => ['40', ['FunctionType' => 2, 'Domain' => [-1, 1], 'N' => 0.5], 'non-negative /Domain'];
        yield 'negative exponent zero at lower endpoint' => ['40', ['FunctionType' => 2, 'Domain' => [0, 1], 'N' => -2], 'excludes zero'];
        yield 'negative exponent zero at upper endpoint' => ['40', ['FunctionType' => 2, 'Domain' => [-1, 0], 'N' => -2], 'excludes zero'];
        yield 'negative exponent zero inside interval' => ['40', ['FunctionType' => 2, 'Domain' => [-1, 1], 'N' => -2], 'excludes zero'];
        foreach (['application/a#b', 'text/plain;charset=utf-8', 'text/a=b', 'application/', '/plain', 'text/plain/extra', 'text/pla in', "text/plain\n"] as $name) {
            yield 'MIME ' . $name => ['44', ['Subtype' => new NameType($name)], 'MIME Subtype'];
        }
        foreach (['48', '49', 'F.1'] as $table) {
            foreach (['Metadata', 'AF'] as $key) {
                yield $table . ' prohibits ' . $key => [$table, [$key => new DictionaryType()], 'forbids /' . $key];
            }
            yield $table . ' nested reference' => [$table, ['Private' => new ArrayType(new DictionaryType(new Entry(new NameType('Hidden'), new IndirectReference(new IntegerType(1)))))], 'shall be direct'];
            yield $table . ' nested stream' => [$table, ['Private' => new ArrayType(new StreamObject('payload'))], 'shall be direct'];
        }
        foreach (['93', '359'] as $table) {
            yield $table . ' both structural parents' => [$table, ['StructParent' => 0, 'StructParents' => 1], '/StructParents is prohibited'];
        }
        foreach (['36' => 'Names', '37' => 'Nums'] as $table => $key) {
            yield $key . ' null key' => [(string) $table, [$key => [new NullType(), new IntegerType(1)]], 'never null'];
            yield $key . ' indirect null key' => [(string) $table, [$key => [new IndirectReference(new NullType()), new IntegerType(1)]], 'never null'];
            yield $key . ' incomplete pair' => [(string) $table, [$key => [new IntegerType(1)]], 'complete key/value pairs'];
        }
        yield 'numeric string comparison is lexical' => ['36', ['Names' => [new LiteralString('2'), 0, new LiteralString('10'), 0]], 'strictly increasing'];
        yield 'number ordering is numeric' => ['37', ['Nums' => [10, 0, 2, 0]], 'strictly increasing'];
        yield 'duplicate name key' => ['36', ['Names' => [new LiteralString('a'), 0, new LiteralString('a'), 1]], 'strictly increasing'];
        yield 'duplicate number key' => ['37', ['Nums' => [1, 0, 1, 1]], 'strictly increasing'];
        foreach (['354' => ['K'], '355' => ['K', 'Ref', 'A', 'C']] as $table => $keys) {
            foreach ($keys as $key) {
                yield $table . '/' . $key . ' explicit null' => [(string) $table, [$key => new NullType()], 'shall not be null'];
                yield $table . '/' . $key . ' indirect null member' => [(string) $table, [$key => [new IndirectReference(new NullType())]], 'shall not be null'];
            }
        }
        foreach (['Metadata', 'AF'] as $key) {
            yield 'signature digest prohibits ' . $key => ['255', ['ByteRange' => [0, 1, 2, 3], $key => new DictionaryType()], 'is prohibited by /ByteRange'];
        }
        yield 'radio inner collection empty' => ['99', ['RBGroups' => [[]]], 'nonempty inner arrays'];
        yield 'radio group must be indirect' => ['99', ['RBGroups' => [[new DictionaryType()]]], 'indirect dictionary references'];
        yield 'font weight too low' => ['120', ['FontWeight' => 0], 'numeric domain'];
        yield 'font weight too high' => ['120', ['FontWeight' => 1000.5], 'numeric domain'];
        yield 'empty rich media configuration list' => ['341', ['Configurations' => []], 'at least 1'];
        yield 'direct script file specification' => ['335', ['Scripts' => [new DictionaryType()]], 'indirect dictionary references'];
        yield 'empty page-tree children' => ['30', ['Kids' => []], 'at least 1'];
        yield 'null page-tree child' => ['30', ['Kids' => [new NullType()]], 'indirect dictionary references'];
        yield 'zero page-tree count' => ['30', ['Count' => 0], 'numeric domain'];
        yield 'DPart metadata is obsolete' => ['409', ['Metadata' => new IndirectReference(new StreamObject('xmp'))], 'EC3 #290'];
        yield 'DPart child array cannot be empty' => ['409', ['DParts' => []], 'EC3 #612'];
        yield 'DPart child cannot be direct' => ['409', ['DParts' => [new DictionaryType()]], 'EC3 #612'];
        yield 'DPart child arrays cannot be nested' => ['409', ['DParts' => [[new IndirectReference(new DictionaryType())]]], 'EC3 #612'];
        yield 'DPart branch cannot claim pages' => ['409', ['DParts' => [new IndirectReference(new DictionaryType())], 'Start' => new IndirectReference(new DictionaryType())], '/Start or /End'];
    }

    public function testAllowedBoundaryCasesAndStructureParentTreeNullHolesArePreserved(): void
    {
        foreach ([[-2, -1, 2], [-2, -1, -2], [0, 1, 0.5], [-1, 1, 0], [0.5, 1, -0.5]] as [$minimum, $maximum, $exponent]) {
            $this->validate('40', $this->dictionary(['FunctionType' => 2, 'Domain' => [$minimum, $maximum], 'N' => $exponent]));
        }
        foreach (['text/plain', 'application/mathml+xml', 'application/vnd.vendor-name+json', 'TEXT/PLAIN'] as $value) {
            $type = new EmbeddedFileMediaType(new NameType($value));
            $this->validate('44', $this->dictionary(['Subtype' => $type->name]));
            self::assertStringContainsString('#2F', $type->name->toPdf(new SerializationContext()));
        }
        foreach (['48', '49', 'F.1'] as $table) {
            $this->validate($table, $this->dictionary(['Private' => [new DictionaryType()], 'Metadata' => new NullType()]));
        }
        foreach (['93', '359'] as $table) {
            $this->validate($table, $this->dictionary(['StructParent' => 0, 'StructParents' => new NullType()]));
            $this->validate($table, $this->dictionary(['StructParents' => 0]));
        }
        $this->validate('36', $this->dictionary(['Names' => [new LiteralString('10'), new NullType(), new LiteralString('2'), new NullType()]]));
        $this->validate('37', $this->dictionary(['Nums' => [-10, new NullType(), 2, new NullType()]]));
        $this->validate('354', $this->dictionary(['ParentTree' => new DictionaryType(new Entry(new NameType('Nums'), new ArrayType(new IntegerType(0), new ArrayType(new NullType()))))]));
        $this->validate('355', $this->dictionary(['K' => 0, 'Ref' => [], 'A' => [new DictionaryType(), 0], 'C' => [new NameType('Class'), 0]]));
        $this->validate('255', $this->dictionary(['Metadata' => new DictionaryType(), 'Reference' => [new DictionaryType(new Entry(new NameType('Data'), new IndirectReference(new DictionaryType())))]]));
        $this->validate('409', $this->dictionary(['DParts' => [new IndirectReference(new DictionaryType())]]));
        $this->validate('409', $this->dictionary(['Metadata' => new NullType()]));
        $this->validate('99', $this->dictionary(['RBGroups' => []]));
        $this->validate('99', $this->dictionary(['RBGroups' => [[new IndirectReference(new DictionaryType())]]]));
        $this->validate('341', $this->dictionary(['Configurations' => [new IndirectReference(new DictionaryType())]]));
        $this->validate('335', $this->dictionary(['Scripts' => []]));
        $this->validate('30', $this->dictionary(['Kids' => [new IndirectReference(new DictionaryType())], 'Count' => 1]));
        foreach ([1, 450.5, 1000] as $weight) {
            $this->validate('120', $this->dictionary(['FontWeight' => $weight]));
        }
        self::assertTrue(true);
    }

    public function testGeneratedSchemaExecutesExponentConstraintsWhenSerialized(): void
    {
        $schema = new class (new Type2FunctionDictionaryTable40Definition(), ...iterator_to_array($this->dictionary(['FunctionType' => 2, 'Domain' => [-1, 1], 'N' => 2])->entries())) extends SchemaDictionary {};
        self::assertStringContainsString('/N 2', $schema->toPdf(new SerializationContext()));
        $invalid = $schema->with(new Entry(new NameType('N'), new NumericType(0.5)));
        $this->expectException(\InvalidArgumentException::class);
        $invalid->toPdf(new SerializationContext());
    }

    public function testNativeEmbeddedFileRetainsPdf17FirstClassNamesButHonoursCatalogUpgrade(): void
    {
        $stream = new EmbeddedFileStream('data', new NameType('VENDOR_Private'));
        $stream->validateVersion(new EffectivePdfVersion(new FileHeader('1.7'), new DictionaryType()));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('MIME Subtype');
        $stream->validateVersion(new EffectivePdfVersion(new FileHeader('1.7'), $this->dictionary(['Version' => new NameType('2.0')])));
    }

    public function testDirectObjectGraphHasExplicitDepthAndObjectBudgets(): void
    {
        $dictionary = $this->dictionary(['Private' => [new DictionaryType()]]);
        (new DirectDictionaryObjects(maximumDepth: 2, maximumObjects: 3))->validate($dictionary);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('explicit limits');
        (new DirectDictionaryObjects(maximumDepth: 1))->validate($dictionary);
    }

    public function testDirectObjectGraphCountsRepeatedEdgesAgainstItsBudget(): void
    {
        $value = new IntegerType(0);
        $dictionary = $this->dictionary(['Private' => [$value, $value, $value]]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('explicit limits');
        (new DirectDictionaryObjects(maximumObjects: 4))->validate($dictionary);
    }

    private function validate(string $table, DictionaryType $dictionary): void
    {
        foreach ((new Catalogue())->dictionary($table)->semanticRules() as $rule) {
            $rule->validate($dictionary);
        }
    }

    public function testRichMediaConfigurationsAreRequiredAndReferencesAreChecked(): void
    {
        $dictionary = new \PDF\Specification\Iso32000_2\Dictionary\RichMediaContentDictionaryTable341();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('/Configurations is missing');
        $dictionary->validate();
    }

    public function testDeveloperExtensionUrlIsRequiredInPdf20Schema(): void
    {
        $dictionary = new \PDF\Specification\Iso32000_2\Dictionary\DeveloperExtensionsDictionaryTable49(
            new Entry(new NameType('BaseVersion'), new NameType('2.0')),
            new Entry(new NameType('ExtensionLevel'), new IntegerType(1)),
        );
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('/URL is missing');
        $dictionary->validate();
    }

    public function testNewVisibilityAndScriptEntriesAreExecutableObjects(): void
    {
        $visibility = new \PDF\Specification\Iso32000_2\Dictionary\ThreeDCrossSectionDictionaryTable322\PVEntry(new \PDF\Syntax\Object\BooleanType(false));
        self::assertStringContainsString('/PV false', (new DictionaryType($visibility))->toPdf(new SerializationContext()));
        $scripts = new \PDF\Specification\Iso32000_2\Dictionary\RichMediaActivationDictionaryTable335\ScriptsEntry(new ArrayType());
        $activation = new \PDF\Specification\Iso32000_2\Dictionary\RichMediaActivationDictionaryTable335($scripts);
        self::assertStringContainsString('/Scripts []', $activation->toPdf(new SerializationContext()));
    }

    private function dictionary(array $values): DictionaryType
    {
        $entries = [];
        foreach ($values as $key => $value) {
            $entries[] = new Entry(new NameType($key), $this->value($value));
        }
        return new DictionaryType(...$entries);
    }

    private function value(mixed $value): BasicType
    {
        return match (true) {
            $value instanceof BasicType => $value,
            is_int($value) => new IntegerType($value),
            is_float($value) => new NumericType($value),
            is_array($value) => new ArrayType(...array_map($this->value(...), $value)),
            default => throw new \LogicException('Invalid test object.'),
        };
    }
}
