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
use PDF\Syntax\Object\StreamObject;
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

    #[DataProvider('missingMediaTypes')]
    public function testEmbeddedAssetsRequireMimeSubtypeEvenOutsideTheNameTree(bool $primary): void
    {
        [$annotation] = $this->fixture($primary ? null : new NameType('application/octet-stream'), $primary ? new NameType('application/javascript') : null);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('MIME Subtype');
        $this->document(CrossReferenceFormat::Table, $annotation)->toPdf();
    }

    public static function missingMediaTypes(): iterable
    {
        yield 'unnamed primary instance' => [true];
        yield 'named script asset' => [false];
    }

    #[DataProvider('formats')]
    public function testIncrementalReplacementCannotRemoveAssetSubtype(CrossReferenceFormat $format): void
    {
        [$annotation, $content] = $this->fixture();
        $primary = $content->get('Configurations')->items[0]->target()->get('Instances')->items[0]->target()->get('Asset')->target();
        $stream = $primary->get('EF')->get('F')->target();
        $revision = $this->document($format, $annotation)->toRevision();
        $replacement = new StreamObject($stream->data, new DictionaryType($this->entry('Type', new NameType('EmbeddedFile'))));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('MIME Subtype');
        (new IncrementalUpdate($revision, new FileBody(new IndirectObject($replacement, $revision->binding($stream)->identifier))))->toRevision();
    }

    /** @return iterable<string,array{string,BasicType}> */
    public static function invalidInstanceValues(): iterable
    {
        yield 'missing required Subtype' => ['Subtype', new \PDF\Syntax\Object\NullType()];
        yield 'unknown Subtype' => ['Subtype', new NameType('Flash')];
        yield 'non-name Subtype' => ['Subtype', new IntegerType(1)];
        yield 'wrong Type' => ['Type', new NameType('NotAnInstance')];
    }

    #[DataProvider('invalidInstanceValues')]
    public function testFrozenInstanceCannotLoseItsRequiredContentType(string $key, BasicType $value): void
    {
        [$annotation, $content] = $this->fixture();
        $instance = $content->get('Configurations')->items[0]->target()->get('Instances')->items[0]->target();
        $base = $this->document(CrossReferenceFormat::Table, $annotation)->toRevision();
        $record = $base->binding($instance);
        $changed = ($record->dictionary ?? throw new \LogicException('Missing fixture instance.'))->with($this->entry($key, $value));
        $this->expectException(\InvalidArgumentException::class);
        (new IncrementalUpdate($base, new FileBody(new IndirectObject($changed, $record->identifier))))->toRevision();
    }

    /** @return iterable<string,array{string,BasicType}> */
    public static function invalidSettings(): iterable
    {
        yield 'activation condition' => ['Condition', new NameType('Bad')];
        yield 'animation dictionary' => ['Animation', new IntegerType(1)];
        yield 'animation subtype type' => ['Animation.Subtype', new IntegerType(1)];
        yield 'animation speed' => ['Animation.Speed', new IntegerType(0)];
        yield 'animation real play count' => ['Animation.PlayCount', new \PDF\Syntax\Object\RealType(1)];
        yield 'presentation style' => ['Presentation.Style', new NameType('Floating')];
        yield 'presentation toolbar type' => ['Presentation.Toolbar', new IntegerType(1)];
        yield 'window dictionary' => ['Presentation.Window', new IndirectReference(new StreamObject(''))];
        yield 'window reversed limits' => ['Presentation.Window.Width.Min', new IntegerType(600)];
        yield 'window invalid dimension' => ['Presentation.Window.Height.Max', new NameType('Tall')];
        yield 'position alignment' => ['Presentation.Window.Position.HAlign', new NameType('Left')];
        yield 'position offset' => ['Presentation.Window.Position.VOffset', new NameType('Up')];
    }

    #[DataProvider('invalidSettings')]
    public function testNativeNestedSettingsValidateTheirPdfValues(string $path, BasicType $value): void
    {
        [$annotation, , $settings, $activation] = $this->fixture();
        $parts = explode('.', $path);
        while (count($parts) > 1) {
            $value = new DictionaryType($this->entry(array_pop($parts), $value));
        }
        $activation = $activation->with($this->entry($parts[0], $value));
        $annotation = $annotation->with($this->entry('RichMediaSettings', $settings->with($this->entry('Activation', $activation))));
        $this->expectException(\InvalidArgumentException::class);
        $this->document(CrossReferenceFormat::Table, $annotation)->toRevision();
    }

    public function testUnknownAnimationStyleKeepsItsNormativeNoneFallbackAndNegativeRepeatCount(): void
    {
        [$annotation, , $settings, $activation] = $this->fixture();
        $animation = new DictionaryType($this->entry('Subtype', new NameType('FutureStyle')), $this->entry('PlayCount', new IntegerType(-50)));
        $activation = $activation->with($this->entry('Animation', $animation));
        $annotation = $annotation->with($this->entry('RichMediaSettings', $settings->with($this->entry('Activation', $activation))));
        self::assertStringContainsString('/FutureStyle', $this->document(CrossReferenceFormat::Table, $annotation)->toPdf());
    }

    public function testMissingOptionalInstancesAndAllMediaSubtypesRemainValid(): void
    {
        [$annotation, $content] = $this->fixture();
        $configurations = [];
        foreach (['3D', 'Sound', 'Video'] as $type) {
            $configurations[] = new IndirectReference(new DictionaryType($this->entry('Subtype', new NameType($type))));
        }
        $annotation = $annotation->with($this->entry('RichMediaContent', $content->with($this->entry('Configurations', new ArrayType(...$configurations)))))->without('RichMediaSettings');
        self::assertStringContainsString('/Subtype /3D', $this->document(CrossReferenceFormat::Table, $annotation)->toPdf());
    }

    /** @return iterable<string,array{string}> */
    public static function invalidAssetNames(): iterable
    {
        foreach (['', 'trailing.', 'bad:name', 'bad*name', 'bad"name', 'bad<name', 'bad>name', 'bad|name', "bad\0name", 'bad%xy', 'raw space', str_repeat('a', 256)] as $name) {
            yield bin2hex($name) => [$name];
        }
    }

    #[DataProvider('invalidAssetNames')]
    public function testNamedAssetRelativeUriRestrictions(string $name): void
    {
        [$annotation, $content] = $this->fixture();
        $assets = new NameTree(new NameTreeEntry(new LiteralString($name), new IndirectReference($this->file($name))));
        $annotation = $annotation->with($this->entry('RichMediaContent', $content->with($this->entry('Assets', new IndirectReference($assets)))))->without('RichMediaSettings');
        $this->expectException(\InvalidArgumentException::class);
        $this->document(CrossReferenceFormat::Table, $annotation)->toRevision();
    }

    public function testNamedAssetRequiresMatchingFAndUnicodeFilenames(): void
    {
        [$annotation, $content] = $this->fixture();
        $assets = new NameTree(new NameTreeEntry(new LiteralString('other.js'), new IndirectReference($this->file('script.js'))));
        $annotation = $annotation->with($this->entry('RichMediaContent', $content->with($this->entry('Assets', new IndirectReference($assets)))))->without('RichMediaSettings');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('matching name-tree');
        $this->document(CrossReferenceFormat::Table, $annotation)->toRevision();
    }

    public function testZeroAndPercentEncodedNamesAreNotMistakenForMissingValues(): void
    {
        foreach (['0', 'images/caf%C3%A9.png', str_repeat('a', 255)] as $name) {
            [$annotation, $content] = $this->fixture();
            $assets = new NameTree(new NameTreeEntry(new LiteralString($name), new IndirectReference($this->file($name))));
            $annotation = $annotation->with($this->entry('RichMediaContent', $content->with($this->entry('Assets', new IndirectReference($assets)))))->without('RichMediaSettings');
            self::assertStringContainsString('/RichMedia', $this->document(CrossReferenceFormat::Table, $annotation)->toPdf());
        }
    }

    public function testConfigurationNamesAreComparedAsDecodedUnicode(): void
    {
        [$annotation, $content] = $this->fixture();
        $configurations = new ArrayType(
            new IndirectReference(new DictionaryType($this->entry('Name', new LiteralString('same')))),
            new IndirectReference(new DictionaryType($this->entry('Name', new LiteralString("\xfe\xff\0s\0a\0m\0e")))),
        );
        $annotation = $annotation->with($this->entry('RichMediaContent', $content->with($this->entry('Configurations', $configurations))))->without('RichMediaSettings');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('names must be unique');
        $this->document(CrossReferenceFormat::Table, $annotation)->toRevision();
    }

    #[DataProvider('changedUnicodeNames')]
    public function testFrozenNamedAssetRequiresItsMatchingUnicodeFilename(BasicType $name): void
    {
        [$annotation, $content] = $this->fixture();
        $file = $content->get('Assets')->target()->get('Names')->items[1]->target();
        $base = $this->document(CrossReferenceFormat::Table, $annotation)->toRevision();
        $record = $base->binding($file);
        $changed = ($record->dictionary ?? throw new \LogicException('Missing file specification.'))->with($this->entry('UF', $name));
        $this->expectException(\InvalidArgumentException::class);
        (new IncrementalUpdate($base, new FileBody(new IndirectObject($changed, $record->identifier))))->toRevision();
    }

    public static function changedUnicodeNames(): iterable
    {
        yield 'missing' => [new \PDF\Syntax\Object\NullType()];
        yield 'different' => [new LiteralString('different.js')];
    }

    public function testRichMediaActionsUseTheirActualAnnotationAndInstance(): void
    {
        [$annotation, $content] = $this->fixture();
        $instance = $content->get('Configurations')->items[0]->target()->get('Instances')->items[0];
        $command = new DictionaryType($this->entry('C', new \PDF\Syntax\DataStructure\TextString('play')), $this->entry('A', new ArrayType(new IntegerType(1), new \PDF\Syntax\Object\BooleanType(false), new LiteralString('arg'))));
        $view = new DictionaryType($this->entry('S', new NameType('GoTo3DView')), $this->entry('TA', new IndirectReference($annotation)), $this->entry('V', new NameType('D')));
        $action = new DictionaryType($this->entry('S', new NameType('RichMediaExecute')), $this->entry('TA', new IndirectReference($annotation)), $this->entry('TI', $instance), $this->entry('CMD', $command), $this->entry('Next', $view));
        $page = new PageObject(new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(100), new IntegerType(100)), new ResourceDictionary(), null, $this->entry('Annots', new ArrayType(new IndirectReference($annotation))));
        $catalog = new DocumentCatalog((new PageTreeNode())->addKid($page), $this->entry('OpenAction', new IndirectReference($action)));
        $base = (new FileStructure(new FileHeader('2.0'), new FileBody($catalog)))->toRevision();
        self::assertStringContainsString('/GoTo3DView', $base->bytes);
        $record = $base->binding($action);
        $changed = ($record->dictionary ?? throw new \LogicException('Missing action.'))->with($this->entry('TI', new IndirectReference(new DictionaryType())));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('instance of its target annotation');
        (new IncrementalUpdate($base, new FileBody(new IndirectObject($changed, $record->identifier))))->toRevision();
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
    private function fixture(?NameType $primarySubtype = new NameType('application/octet-stream'), ?NameType $scriptSubtype = new NameType('application/javascript')): array
    {
        $script = $this->file('script.js', $scriptSubtype);
        $primary = $this->file('movie.bin', $primarySubtype);
        $instance = new DictionaryType($this->entry('Subtype', new NameType('Video')), $this->entry('Asset', new IndirectReference($primary)));
        $configuration = new DictionaryType($this->entry('Instances', new ArrayType(new IndirectReference($instance))));
        $view = new DictionaryType();
        $content = new DictionaryType($this->entry('Configurations', new ArrayType(new IndirectReference($configuration))), $this->entry('Views', new ArrayType(new IndirectReference($view))), $this->entry('Assets', new IndirectReference(new NameTree(new NameTreeEntry(new LiteralString('script.js'), new IndirectReference($script))))));
        $activation = new DictionaryType($this->entry('Scripts', new ArrayType(new IndirectReference($script))), $this->entry('Configuration', new IndirectReference($configuration)), $this->entry('View', new IndirectReference($view)));
        $settings = new DictionaryType($this->entry('Activation', $activation));
        $annotation = new DictionaryType($this->entry('Type', new NameType('Annot')), $this->entry('Subtype', new NameType('RichMedia')), $this->entry('Rect', new ArrayType(new IntegerType(0), new IntegerType(0), new IntegerType(0), new IntegerType(0))), $this->entry('RichMediaContent', new IndirectReference($content)), $this->entry('RichMediaSettings', new IndirectReference($settings)));
        return [$annotation, $content, $settings, $activation];
    }

    private function file(string $name, ?NameType $subtype = new NameType('application/octet-stream')): F\FileSpecification
    {
        return new F\FileSpecification(new LiteralString($name), new F\FileSpecificationOptions(embedded: new F\EmbeddedFileBindings(new F\EmbeddedFileBinding(F\EmbeddedFileKey::File, new F\EmbeddedFileStream('Explicit opaque test asset', $subtype))), additionalNames: new F\FileSpecificationName(F\EmbeddedFileKey::UnicodeFile, new \PDF\Syntax\DataStructure\TextString($name))));
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
