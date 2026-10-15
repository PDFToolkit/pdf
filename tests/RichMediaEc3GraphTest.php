<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\Files as F;
use PDF\Document\ResourceDictionary;
use PDF\FileStructure;
use PDF\Syntax\DataStructure\NameTree;
use PDF\Syntax\DataStructure\NameTreeEntry;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\FileStructure\CrossReferenceFormat;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\FileStructure\IncrementalUpdate;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringObject\LiteralString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RichMediaEc3GraphTest extends TestCase
{
    #[DataProvider('formats')]
    public function testScriptsBelongToAssetsButThePrimaryInstanceAssetMayBeUnnamed(CrossReferenceFormat $format): void
    {
        [$annotation, $content] = $this->fixture();
        $revision = $this->document($format, $annotation)->toRevision();
        self::assertStringContainsString('/RichMediaContent', $revision->bytes);
        $replacement = (new DictionaryType(...iterator_to_array($content->entries(), false)))->without('Assets');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Assets name tree');
        (new IncrementalUpdate($revision, new FileBody(new IndirectObject($replacement, $revision->binding($content)->identifier))))->toRevision();
    }

    public function testAnEmptyScriptArrayAndAbsentAssetsAreAllowed(): void
    {
        [$annotation, $content, $settings, $activation] = $this->fixture();
        $activation = $activation->with($this->entry('Scripts', new ArrayType()));
        $settings = $settings->with($this->entry('Activation', $activation));
        $annotation = $annotation->with($this->entry('RichMediaContent', new IndirectReference($content->without('Assets'))))->with($this->entry('RichMediaSettings', $settings));
        self::assertStringContainsString('/Scripts []', $this->document(CrossReferenceFormat::Table, $annotation)->toPdf());
    }

    #[DataProvider('sharedKeys')]
    public function testContentAndSettingsCannotBeSharedBetweenAnnotations(string $key): void
    {
        [$first] = $this->fixture();
        [$second] = $this->fixture();
        $second = $second->with($this->entry($key, $first->get($key) ?? throw new \LogicException('The fixture has both owned dictionaries.')));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unique to each annotation');
        $this->document(CrossReferenceFormat::Table, $first, $second)->toPdf();
    }

    public function testRepeatingOneAnnotationReferenceDoesNotCreateAnotherOwner(): void
    {
        [$annotation] = $this->fixture();
        self::assertStringContainsString('/RichMedia', $this->document(CrossReferenceFormat::Table, $annotation, $annotation)->toPdf());
    }

    #[DataProvider('selectionKeys')]
    public function testActivationSelectionsMustBelongToTheirOwnContent(string $key): void
    {
        [$annotation, , $settings, $activation] = $this->fixture();
        $activation = $activation->with($this->entry($key, new IndirectReference(new DictionaryType())));
        $annotation = $annotation->with($this->entry('RichMediaSettings', $settings->with($this->entry('Activation', $activation))));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must select an entry');
        $this->document(CrossReferenceFormat::Table, $annotation)->toPdf();
    }

    public function testSameNamedButDifferentScriptFileIsNotAnAssetMember(): void
    {
        [$annotation, , $settings, $activation] = $this->fixture();
        $activation = $activation->with($this->entry('Scripts', new ArrayType(new IndirectReference($this->file('script.js')))));
        $annotation = $annotation->with($this->entry('RichMediaSettings', $settings->with($this->entry('Activation', $activation))));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Assets name tree');
        $this->document(CrossReferenceFormat::Table, $annotation)->toPdf();
    }

    public function testConfigurationsCannotBeEmptyInTheEffectiveGraph(): void
    {
        [$annotation, $content] = $this->fixture();
        $annotation = $annotation->with($this->entry('RichMediaContent', $content->with($this->entry('Configurations', new ArrayType()))));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('nonempty');
        $this->document(CrossReferenceFormat::Table, $annotation)->toPdf();
    }

    public static function formats(): iterable
    {
        foreach (CrossReferenceFormat::cases() as $format) {
            yield $format->name => [$format];
        }
    }

    public static function sharedKeys(): iterable
    {
        yield ['RichMediaContent'];
        yield ['RichMediaSettings'];
    }

    public static function selectionKeys(): iterable
    {
        yield ['View'];
        yield ['Configuration'];
    }

    /** @return array{DictionaryType,DictionaryType,DictionaryType,DictionaryType} */
    private function fixture(): array
    {
        $script = $this->file('script.js');
        $primary = $this->file('movie.bin');
        $instance = new DictionaryType($this->entry('Subtype', new NameType('Video')), $this->entry('Asset', new IndirectReference($primary)));
        $configuration = new DictionaryType($this->entry('Instances', new ArrayType(new IndirectReference($instance))));
        $view = new DictionaryType();
        $content = new DictionaryType($this->entry('Configurations', new ArrayType(new IndirectReference($configuration))), $this->entry('Views', new ArrayType(new IndirectReference($view))), $this->entry('Assets', new IndirectReference(new NameTree(new NameTreeEntry(new LiteralString('script.js'), new IndirectReference($script))))));
        $activation = new DictionaryType($this->entry('Scripts', new ArrayType(new IndirectReference($script))), $this->entry('Configuration', new IndirectReference($configuration)), $this->entry('View', new IndirectReference($view)));
        $settings = new DictionaryType($this->entry('Activation', $activation));
        $annotation = new DictionaryType($this->entry('Type', new NameType('Annot')), $this->entry('Subtype', new NameType('RichMedia')), $this->entry('Rect', new ArrayType(new IntegerType(0), new IntegerType(0), new IntegerType(0), new IntegerType(0))), $this->entry('RichMediaContent', new IndirectReference($content)), $this->entry('RichMediaSettings', new IndirectReference($settings)));
        return [$annotation, $content, $settings, $activation];
    }

    private function file(string $name): F\FileSpecification
    {
        return new F\FileSpecification(new LiteralString($name), new F\FileSpecificationOptions(embedded: new F\EmbeddedFileBindings(new F\EmbeddedFileBinding(F\EmbeddedFileKey::File, new F\EmbeddedFileStream('Explicit opaque test asset', new NameType('application/octet-stream'))))));
    }

    private function document(CrossReferenceFormat $format, DictionaryType ...$annotations): FileStructure
    {
        $page = new PageObject(new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(100), new IntegerType(100)), new ResourceDictionary(), null, $this->entry('Annots', new ArrayType(...array_map(static fn (DictionaryType $annotation): IndirectReference => new IndirectReference($annotation), $annotations))));
        $catalog = new DocumentCatalog((new PageTreeNode())->addKid($page));
        return new FileStructure(new FileHeader('2.0'), new FileBody($catalog), crossReferenceFormat: $format);
    }

    private function entry(string $key, BasicType $value): Entry
    {
        return new Entry(new NameType($key), $value);
    }
}
