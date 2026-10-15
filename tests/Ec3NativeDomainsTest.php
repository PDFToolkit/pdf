<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\Extensions\DeveloperExtension;
use PDF\Document\Fonts\FontDescriptor;
use PDF\Document\Fonts\FontDescriptorFlags;
use PDF\Document\ResourceDictionary;
use PDF\FileStructure;
use PDF\Graphics\OptionalContent\GroupCollection;
use PDF\Graphics\OptionalContent\RadioButtonGroups;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StringObject\LiteralString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Ec3NativeDomainsTest extends TestCase
{
    public function testEmptyOuterRadioCollectionIsAnExplicitReset(): void
    {
        self::assertCount(0, new RadioButtonGroups());
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('EC3 #225');
        new RadioButtonGroups(new GroupCollection());
    }

    #[DataProvider('pdf20Weights')]
    public function testPdf20FontWeightsUseTheFullIntegerRange(int|float $weight): void
    {
        $descriptor = $this->descriptor($weight);
        $descriptor->validateVersion($this->version('2.0'));
        $descriptor->validateVersion(new EffectivePdfVersion(new FileHeader('1.7'), new DictionaryType(new Entry(new NameType('Version'), new NameType('2.0')))));
        self::assertSame($weight, $descriptor->get('FontWeight')->value);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('requires PDF 2.0');
        $descriptor->validateVersion($this->version('1.7'));
    }

    public static function pdf20Weights(): iterable
    {
        foreach ([1, 99, 450, 901, 1000] as $weight) {
            yield [$weight];
        }
    }

    #[DataProvider('invalidWeights')]
    public function testWeightsOutsidePdf20DomainAreRejected(int|float $weight): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('EC3 #152/#474');
        $this->descriptor($weight);
    }

    public static function invalidWeights(): iterable
    {
        foreach ([0, -1, 1000.5, 450.5] as $weight) {
            yield [$weight];
        }
    }

    public function testTraditionalWeightsRemainValidInPdf17(): void
    {
        foreach (range(100, 900, 100) as $weight) {
            $descriptor = $this->descriptor($weight);
            $descriptor->validateVersion($this->version('1.7'));
            self::assertSame($weight, $descriptor->get('FontWeight')->value);
        }
    }

    public function testPdf20RequiresAnExplicitIntegerWeightObject(): void
    {
        $descriptor = $this->descriptor(700.0);
        $descriptor->validateVersion($this->version('1.7'));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('IntegerType (EC3 #152)');
        $descriptor->validateVersion($this->version('2.0'));
    }

    public function testDeveloperUrlIsRequiredByEffectivePdf20Only(): void
    {
        $extension = new DeveloperExtension(new NameType('1.7'), new IntegerType(3));
        $extension->validateVersion($this->version('1.7'));
        $documented = $extension->with(new Entry(new NameType('URL'), new LiteralString('https://example.org/pdf-extension')));
        $documented->validateVersion($this->version('2.0'));
        self::assertTrue($documented->has('URL'));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('EC3 #732');
        $extension->validateVersion(new EffectivePdfVersion(new FileHeader('1.7'), new DictionaryType(new Entry(new NameType('Version'), new NameType('2.0')))));
    }

    public function testPdf17KeepsItsEmptyTreeBehavior(): void
    {
        self::assertStringContainsString('/Count 0', $this->file('1.7', new PageTreeNode())->toPdf());
    }

    #[DataProvider('emptyTrees')]
    public function testPdf20RejectsEmptyRootAndNestedPageTrees(bool $nested, bool $catalogUpgrade): void
    {
        $tree = new PageTreeNode();
        if ($nested) {
            $tree->addKid($this->blankPage())->addKid(new PageTreeNode());
        }
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('EC3 #271');
        $this->file($catalogUpgrade ? '1.7' : '2.0', $tree, $catalogUpgrade)->toPdf();
    }

    public static function emptyTrees(): iterable
    {
        yield [false, false];
        yield [true, false];
        yield [false, true];
    }

    public function testPageWithoutContentsStillCarriesResources(): void
    {
        $tree = (new PageTreeNode())->addKid($this->blankPage());
        $pdf = $this->file('2.0', $tree)->toPdf();
        self::assertStringContainsString('/Resources <<', $pdf);
        self::assertStringNotContainsString('/Contents', $pdf);
    }

    private function descriptor(int|float $weight): FontDescriptor
    {
        return new FontDescriptor(null, new NameType('Shapes'), new FontDescriptorFlags(4), null, new NumericType(0), null, null, null, null, new Entry(new NameType('FontWeight'), is_int($weight) ? new IntegerType($weight) : new NumericType($weight)));
    }

    private function version(string $value): EffectivePdfVersion
    {
        return new EffectivePdfVersion(new FileHeader($value), new DictionaryType());
    }

    private function blankPage(): PageObject
    {
        return new PageObject(new Rectangle(new NumericType(0), new NumericType(0), new NumericType(10), new NumericType(10)), new ResourceDictionary());
    }

    private function file(string $header, PageTreeNode $tree, bool $upgrade = false): FileStructure
    {
        $catalog = new DocumentCatalog($tree, ...($upgrade ? [new Entry(new NameType('Version'), new NameType('2.0'))] : []));
        return new FileStructure(new FileHeader($header), new FileBody($catalog), new IndirectReference($catalog));
    }
}
