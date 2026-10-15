<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\Geospatial\Viewport;
use PDF\Document\Geospatial\Viewports;
use PDF\Document\ResourceDictionary;
use PDF\FileStructure;
use PDF\Specification\Dictionary\RectilinearMeasureDictionaryTable262;
use PDF\Specification\Iso32000_2\Dictionary\RectilinearMeasureDictionaryTable267;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\FileStructure\IncrementalUpdate;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Retained ISO1 12.9 pp489–491; ISO2 12.9.1 pp612–615. */
final class RectilinearMeasureValidationTest extends TestCase
{
    private function e(string $key, BasicType $value): Entry
    {
        return new Entry(new NameType($key), $value);
    }

    private function format(Entry ...$entries): DictionaryType
    {
        return new DictionaryType($this->e('U', new TextString('m')), $this->e('C', new NumericType(1)), ...$entries);
    }

    private function measure(): DictionaryType
    {
        $units = new ArrayType($this->format());
        return new DictionaryType($this->e('R', new TextString('1:1')), $this->e('X', $units), $this->e('D', $units), $this->e('A', $units));
    }

    private function file(DictionaryType $measure, string $version = '1.7'): FileStructure
    {
        $bounds = new Rectangle(new NumericType(0), new NumericType(0), new NumericType(100), new NumericType(100));
        $page = new PageObject($bounds, new ResourceDictionary(), entries: $this->e('VP', new Viewports(new Viewport($bounds, $measure))));
        return new FileStructure(new FileHeader($version), new FileBody(new DocumentCatalog((new PageTreeNode())->addKid($page))));
    }

    /** @return iterable<string,array{string,string}> */
    public static function arrays(): iterable
    {
        foreach (['1.7', '2.0'] as $version) {
            foreach (['X', 'Y', 'D', 'A', 'T', 'S'] as $key) {
                yield $version . ':' . $key => [$version, $key];
            }
        }
    }

    #[DataProvider('arrays')]
    public function testEveryPresentNumberFormatArrayContainsAtLeastOneDictionary(string $version, string $key): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('one or more dictionaries');
        $this->file($this->measure()->with($this->e($key, new ArrayType())), $version)->toRevision();
    }

    public function testGeneratedSchemasComposeWithGraphRulesInBothEditions(): void
    {
        foreach (['1.7' => RectilinearMeasureDictionaryTable262::class, '2.0' => RectilinearMeasureDictionaryTable267::class] as $version => $class) {
            $measure = new $class(...iterator_to_array($this->measure()->entries(), false));
            self::assertStringContainsString('/R (1:1)', $this->file($measure, $version)->toPdf());
        }
    }

    /** @return iterable<string,array{string,BasicType}> */
    public static function invalidFormats(): iterable
    {
        yield 'unit text' => ['U', new NameType('m')];
        yield 'conversion numeric' => ['C', new TextString('1')];
        yield 'fraction mode' => ['F', new NameType('unknown')];
        yield 'integer precision' => ['D', new NumericType(100.5)];
        yield 'positive precision' => ['D', new IntegerType(0)];
        yield 'multiple of ten' => ['D', new IntegerType(16)];
        yield 'fixed fractional boolean' => ['FD', new IntegerType(1)];
        yield 'label order' => ['O', new NameType('unknown')];
        yield 'separator string' => ['RT', new NameType('comma')];
        yield 'decimal string' => ['RD', new IntegerType(0)];
        yield 'prefix string' => ['PS', new NameType('space')];
        yield 'suffix string' => ['SS', new NameType('space')];
        yield 'optional type' => ['Type', new NameType('Other')];
    }

    #[DataProvider('invalidFormats')]
    public function testEffectiveNumberFormatEntriesAreValidated(string $key, BasicType $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->file($this->measure()->with($this->e('X', new ArrayType($this->format()->with($this->e($key, $value))))))->toRevision();
    }

    public function testFractionalDenominatorAndDecimalMultipleAreDifferentControls(): void
    {
        foreach ([
            $this->format($this->e('F', new NameType('F')), $this->e('D', new IntegerType(16)), $this->e('FD', new BooleanType(true))),
            $this->format($this->e('D', new IntegerType(30))), // The source says multiple of ten, not power of ten.
        ] as $format) {
            self::assertStringContainsString('/U (m)', $this->file($this->measure()->with($this->e('X', new ArrayType($format))))->toPdf());
        }
    }

    public function testNonFinalFractionControlsDoNotOverrideTheFinalUnit(): void
    {
        $ignored = $this->format($this->e('F', new NameType('PrivateIgnoredMode')), $this->e('D', new IntegerType(3)));
        $units = new ArrayType($ignored, $this->format($this->e('F', new NameType('T'))));
        self::assertStringContainsString('/F /T', $this->file($this->measure()->with($this->e('X', $units)))->toPdf());
    }

    public function testDifferentAxesWithoutCYXRemainAValidDeclaration(): void
    {
        $measure = $this->measure()->with($this->e('Y', new ArrayType($this->format()->with($this->e('U', new TextString('seconds'))))))->with($this->e('O', new ArrayType(new NumericType(10), new NumericType(20))));
        self::assertStringContainsString('/U (seconds)', $this->file($measure)->toPdf());
    }

    public function testNoUnstatedGlobalPositiveConversionConstraintIsInvented(): void
    {
        foreach ([0, -1] as $value) {
            $format = $this->format()->with($this->e('C', new NumericType($value)));
            self::assertStringContainsString('/C ' . $value, $this->file($this->measure()->with($this->e('X', new ArrayType($format))))->toPdf());
        }
    }

    public function testUnknownMeasureExtensionIsNotAssumedToHaveRLSemantics(): void
    {
        $extension = new DictionaryType($this->e('Subtype', new NameType('PrivateMeasure')));
        self::assertStringContainsString('/PrivateMeasure', $this->file($extension)->toPdf());
    }

    /** @return iterable<string,array{string,BasicType}> */
    public static function invalidMeasureEntries(): iterable
    {
        yield 'origin width' => ['O', new ArrayType(new NumericType(1))];
        yield 'origin coordinates' => ['O', new ArrayType(new NumericType(1), new NameType('two'))];
        yield 'CYX numeric' => ['CYX', new TextString('1')];
        yield 'ratio text' => ['R', new NameType('ratio')];
    }

    #[DataProvider('invalidMeasureEntries')]
    public function testRectilinearContextEntriesHaveTheirRequiredTypes(string $key, BasicType $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->file($this->measure()->with($this->e($key, $value)))->toRevision();
    }

    public function testFrozenIndirectUnitReplacementCannotBypassLastArrayRules(): void
    {
        $format = $this->format();
        $measure = $this->measure()->with($this->e('X', new ArrayType(new IndirectReference($format))));
        $base = $this->file($measure)->toRevision();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('multiple of ten');
        (new IncrementalUpdate($base, new FileBody(new IndirectObject($format->with($this->e('D', new IntegerType(16))), $base->binding($format)->identifier))))->toRevision();
    }
}
