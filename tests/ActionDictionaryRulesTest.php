<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\ResourceDictionary;
use PDF\FileStructure;
use PDF\Syntax\DataStructure\NameTree;
use PDF\Syntax\DataStructure\NameTreeEntry;
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
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringObject\LiteralString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ActionDictionaryRulesTest extends TestCase
{
    private function e(string $key, BasicType $value): Entry
    {
        return new Entry(new NameType($key), $value);
    }

    private function action(string $kind, Entry ...$entries): DictionaryType
    {
        return new DictionaryType($this->e('S', new NameType($kind)), ...$entries);
    }

    private function file(?BasicType $action, string $version = '2.0', Entry ...$entries): FileStructure
    {
        $page = new PageObject(new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(100), new IntegerType(100)), new ResourceDictionary());
        if ($action !== null) {
            $entries[] = $this->e('OpenAction', new IndirectReference($action));
        }
        return new FileStructure(new FileHeader($version), new FileBody(new DocumentCatalog((new PageTreeNode())->addKid($page), ...$entries)));
    }

    /** @return iterable<string,array{string}> */
    public static function requiredActionTypes(): iterable
    {
        foreach (['URI', 'Named', 'Launch', 'Thread', 'Sound', 'Movie', 'Hide', 'SetOCGState', 'Rendition', 'Trans', 'GoTo3DView', 'JavaScript', 'GoToDp', 'RichMediaExecute', 'SubmitForm', 'ImportData'] as $type) {
            yield $type => [$type];
        }
    }

    #[DataProvider('requiredActionTypes')]
    public function testStandardActionsCannotBeWrittenWithoutRequiredEntries(string $type): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->file($this->action($type))->toRevision();
    }

    public function testFrozenIndirectActionIsCheckedAfterRequiredEntryRemoval(): void
    {
        $action = $this->action('URI', $this->e('URI', new LiteralString('relative.html')));
        $base = $this->file($action)->toRevision();
        $record = $base->binding($action);
        $changed = ($record->dictionary ?? throw new \LogicException('Missing test action.'))->without('URI');
        $this->expectException(\InvalidArgumentException::class);
        (new IncrementalUpdate($base, new FileBody(new IndirectObject($changed, $record->identifier))))->toRevision();
    }

    /** @return iterable<string,array{string,string,BasicType,array<string,BasicType>}> */
    public static function invalidValues(): iterable
    {
        yield 'URI value' => ['URI', 'URI', new NameType('Bad'), []];
        yield 'URI map flag' => ['URI', 'IsMap', new IntegerType(1), ['URI' => new LiteralString('relative')]];
        yield 'Named N' => ['Named', 'N', new TextString('NextPage'), []];
        yield 'script type' => ['JavaScript', 'JS', new NameType('Script'), []];
        yield 'launch window flag' => ['Launch', 'NewWindow', new IntegerType(1), ['F' => new LiteralString('document.pdf')]];
        yield 'thread negative index' => ['Thread', 'D', new IntegerType(-1), []];
        yield 'sound volume' => ['Sound', 'Volume', new NumericType(1.1), ['Sound' => new IndirectReference(new StreamObject('opaque'))]];
        yield 'movie operation' => ['Movie', 'Operation', new NameType('Delete'), ['T' => new TextString('movie')]];
        yield 'movie mutually exclusive target' => ['Movie', 'Annotation', new IndirectReference(new DictionaryType()), ['T' => new TextString('movie')]];
        yield 'hide direct dictionary' => ['Hide', 'T', new DictionaryType(), []];
        yield 'hide nested array' => ['Hide', 'T', new ArrayType(new ArrayType()), []];
        yield 'hide flag type' => ['Hide', 'H', new IntegerType(1), ['T' => new TextString('field')]];
        yield 'OCG dangling operation' => ['SetOCGState', 'State', new ArrayType(new NameType('ON')), []];
        yield 'OCG unknown operation' => ['SetOCGState', 'State', new ArrayType(new NameType('Invert')), []];
        yield 'rendition unknown operation without script' => ['Rendition', 'OP', new IntegerType(9), []];
        yield 'rendition missing R' => ['Rendition', 'OP', new IntegerType(0), []];
        yield 'rendition missing AN' => ['Rendition', 'OP', new IntegerType(1), []];
        yield 'transition invalid style' => ['Trans', 'Trans', new DictionaryType(new Entry(new NameType('S'), new NameType('Bad'))), []];
        yield 'transition dictionary' => ['Trans', 'Trans', new IntegerType(1), []];
        yield 'DPart indirect target' => ['GoToDp', 'Dp', new DictionaryType(), []];
        yield 'submit missing URL filesystem' => ['SubmitForm', 'F', new DictionaryType(), []];
        yield 'reset reserved flag' => ['ResetForm', 'Flags', new IntegerType(2), []];
        yield 'submit reserved flag' => ['SubmitForm', 'Flags', new IntegerType(4096), ['F' => new LiteralString('https://example.invalid')]];
        yield 'submit GET without HTML' => ['SubmitForm', 'Flags', new IntegerType(8), ['F' => new LiteralString('https://example.invalid')]];
        yield 'submit annotations with XFDF' => ['SubmitForm', 'Flags', new IntegerType(32 + 128), ['F' => new LiteralString('https://example.invalid')]];
        yield 'submit exclusion without annotations' => ['SubmitForm', 'Flags', new IntegerType(1024), ['F' => new LiteralString('https://example.invalid')]];
        yield 'form direct field selector' => ['ResetForm', 'Fields', new ArrayType(new DictionaryType()), []];
    }

    #[DataProvider('invalidValues')]
    public function testIntrinsicTypesDomainsAndConditionalEntries(string $kind, string $key, BasicType $value, array $extra): void
    {
        $entries = [$this->e($key, $value)];
        foreach ($extra as $name => $item) {
            $entries[] = $this->e($name, $item);
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->file($this->action($kind, ...$entries))->toRevision();
    }

    public function testPermittedFallbacksEmptySelectionsAndExtensionNamesRemainValid(): void
    {
        $actions = [
            $this->action('URI', $this->e('URI', new LiteralString('relative/path')), $this->e('IsMap', new BooleanType(true))),
            $this->action('Named', $this->e('N', new NameType('VendorAction'))),
            $this->action('Rendition', $this->e('OP', new IntegerType(99)), $this->e('JS', new TextString('/* caller script */'))),
            $this->action('SetOCGState', $this->e('State', new ArrayType())),
            $this->action('Hide', $this->e('T', new ArrayType())),
            $this->action('ResetForm', $this->e('Fields', new ArrayType()), $this->e('Flags', new IntegerType(1))),
            $this->action('SubmitForm', $this->e('F', new LiteralString('https://example.invalid')), $this->e('Flags', new IntegerType(256 + 32 + 128 + 8))),
            $this->action('Launch', $this->e('Mac', new NameType('OpaqueExtension'))),
            $this->action('VendorExtension'),
        ];
        foreach ($actions as $action) {
            self::assertStringContainsString('/OpenAction', $this->file($action)->toPdf());
        }
    }

    public function testActionStreamsAreRejectedEvenWhenTheirDictionaryIsWellFormed(): void
    {
        $stream = new StreamObject('', $this->action('URI', $this->e('URI', new LiteralString('relative'))));
        $this->expectException(\InvalidArgumentException::class);
        $this->file($stream)->toRevision();
    }

    public function testNextActionsAndDocumentJavascriptNameTreeAreActuallyValidated(): void
    {
        $script = $this->action('JavaScript');
        $tree = new NameTree(new NameTreeEntry(new LiteralString('load'), new IndirectReference($script)));
        $names = new DictionaryType($this->e('JavaScript', new IndirectReference($tree)));
        $this->expectException(\InvalidArgumentException::class);
        $this->file(null, '2.0', $this->e('Names', $names))->toRevision();
    }

    public function testDocumentAdditionalActionsRequireEcmascriptEvenWhenOtherActionIsValid(): void
    {
        $additional = new DictionaryType($this->e('WC', $this->action('Named', $this->e('N', new NameType('NextPage')))));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('JavaScript action');
        $this->file(null, '2.0', $this->e('AA', $additional))->toRevision();
    }

    public function testPrivateDictionaryWithSIsNotInventedToBeAnActiveAction(): void
    {
        self::assertStringContainsString('/S /URI', $this->file(null, '2.0', $this->e('Private', $this->action('URI')))->toPdf());
    }

    public function testRegisteredOptionalGroupsCanRepeatAcrossStateSequences(): void
    {
        $group = new DictionaryType($this->e('Type', new NameType('OCG')), $this->e('Name', new TextString('Layer')));
        $reference = new IndirectReference($group);
        $properties = new DictionaryType($this->e('OCGs', new ArrayType($reference)), $this->e('D', new DictionaryType()));
        $action = $this->action('SetOCGState', $this->e('State', new ArrayType(new NameType('ON'), $reference, new NameType('OFF'), $reference)));
        $base = $this->file($action, '2.0', $this->e('OCProperties', new IndirectReference($properties)))->toRevision();
        self::assertStringContainsString('/State [/ON', $base->bytes);
        $record = $base->binding($properties);
        $changed = ($record->dictionary ?? throw new \LogicException('Missing OCProperties.'))->with($this->e('OCGs', new ArrayType()));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('OCProperties.OCGs');
        (new IncrementalUpdate($base, new FileBody(new IndirectObject($changed, $record->identifier))))->toRevision();
    }

    public function testDocumentPartActionMustSelectTheActualTree(): void
    {
        $part = new \PDF\Document\Parts\DocumentPart();
        $root = new \PDF\Document\Parts\DocumentPartRoot($part);
        $page = new PageObject(new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(100), new IntegerType(100)), new ResourceDictionary(), null, new \PDF\Document\Parts\DocumentPartEntry($part));
        $pages = (new PageTreeNode())->addKid($page);
        $part->setPageRange($page);
        $action = $this->action('GoToDp', $this->e('Dp', new IndirectReference($part)));
        $catalog = new DocumentCatalog($pages, $root->catalogEntry(), $this->e('OpenAction', new IndirectReference($action)));
        $base = (new FileStructure(new FileHeader('2.0'), new FileBody($catalog)))->toRevision();
        self::assertStringContainsString('/GoToDp', $base->bytes);
        $record = $base->binding($action);
        $changed = $this->action('GoToDp', $this->e('Dp', new IndirectReference(new DictionaryType())));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('actual catalog DPartRoot');
        (new IncrementalUpdate($base, new FileBody(new IndirectObject($changed, $record->identifier))))->toRevision();
    }

    public function testRenditionRequiresActualScreenWithItsPageReference(): void
    {
        $annotation = new DictionaryType($this->e('Type', new NameType('Annot')), $this->e('Subtype', new NameType('Screen')), $this->e('Rect', new ArrayType(new IntegerType(0), new IntegerType(0), new IntegerType(0), new IntegerType(0))));
        $page = new PageObject(new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(100), new IntegerType(100)), new ResourceDictionary(), null, $this->e('Annots', new ArrayType(new IndirectReference($annotation))));
        $catalog = new DocumentCatalog((new PageTreeNode())->addKid($page));
        $base = (new FileStructure(new FileHeader('2.0'), new FileBody($catalog)))->toRevision();
        $action = $this->action('Rendition', $this->e('OP', new IntegerType(1)), $this->e('AN', new IndirectReference($annotation)));
        $catalogRecord = $base->binding($catalog);
        $annotationRecord = $base->binding($annotation);
        $catalogChange = ($catalogRecord->dictionary ?? throw new \LogicException('Missing catalog.'))->with($this->e('OpenAction', new IndirectReference($action)));
        $annotationChange = ($annotationRecord->dictionary ?? throw new \LogicException('Missing annotation.'))->with($this->e('P', new IndirectReference($page)));
        $revision = (new IncrementalUpdate($base, new FileBody(new IndirectObject($catalogChange, $catalogRecord->identifier), new IndirectObject($annotationChange, $annotationRecord->identifier))))->toRevision();
        self::assertStringContainsString('/Rendition', $revision->bytes);
        $this->expectException(\InvalidArgumentException::class);
        (new IncrementalUpdate($revision, new FileBody(new IndirectObject($annotationChange->without('P'), $annotationRecord->identifier))))->toRevision();
    }

    public function testAWellTypedAnnotationOutsidePageAnnotsIsNotAnActionTarget(): void
    {
        $annotation = new DictionaryType($this->e('Subtype', new NameType('Movie')));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('actual page Annots');
        $this->file($this->action('Movie', $this->e('Annotation', new IndirectReference($annotation))))->toRevision();
    }

    public function testIndirectActionNamesAreResolvedFromEffectiveSnapshot(): void
    {
        $action = new DictionaryType($this->e('S', new IndirectReference(new NameType('URI'))), $this->e('URI', new LiteralString('relative')));
        self::assertStringContainsString('(relative)', $this->file($action)->toPdf());
    }

    public function testVersionOfActionAndNamedFieldSelectorsIsEnforced(): void
    {
        $this->expectException(\LogicException::class);
        $this->file($this->action('ResetForm', $this->e('Fields', new ArrayType(new TextString('field')))), '1.2')->toRevision();
    }
}
