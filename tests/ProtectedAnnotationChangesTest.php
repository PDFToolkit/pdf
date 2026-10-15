<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\Signatures\Policies\DocMdpPermissions;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\FileStructure\CrossReferenceFormat;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileRevision;
use PDF\Syntax\FileStructure\IncrementalUpdate;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;
use PDF\Tests\Support\ProtectedRevisionFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProtectedAnnotationChangesTest extends TestCase
{
    /** @return iterable<string, array{CrossReferenceFormat, string}> */
    public static function representations(): iterable
    {
        foreach (CrossReferenceFormat::cases() as $format) {
            foreach (['1.7', '2.0'] as $version) {
                yield $format->name . $version => [$format, $version];
            }
        }
    }

    #[DataProvider('representations')]
    public function testP3CreatesModifiesAndDetachesMarkupWithoutChangingSignedBytes(CrossReferenceFormat $format, string $version): void
    {
        $fixture = new ProtectedRevisionFixture(DocMdpPermissions::FormsTemplatesSigningAndAnnotations, format: $format, version: $version);
        $annotation = $this->annotation();
        $added = $this->add($fixture, $annotation)->toRevision();
        self::assertStringStartsWith($fixture->revision->bytes, $added->bytes);
        $record = $added->binding($annotation);
        $updated = new IncrementalUpdate($added, new FileBody(new IndirectObject($record->dictionary->with($this->entry('Contents', new TextString('updated review'))), $record->identifier)));
        $modified = $updated->toRevision();
        self::assertStringStartsWith($added->bytes, $modified->bytes);
        $page = $modified->binding($fixture->page);
        $removed = (new IncrementalUpdate($modified, new FileBody(new IndirectObject($page->dictionary->without('Annots'), $page->identifier))))->toRevision();
        self::assertStringStartsWith($modified->bytes, $removed->bytes);
        self::assertSame($modified->binding($annotation)->snapshot, $removed->binding($annotation)->snapshot);
        $fixture->revision->verifiedSignature->validateBinding($fixture->revision);
    }

    public function testP1AndP2NeverAcquireAnnotationAuthority(): void
    {
        foreach ([DocMdpPermissions::NoChanges, DocMdpPermissions::FormFillingTemplatesAndSigning] as $permission) {
            $fixture = new ProtectedRevisionFixture($permission);
            try {
                $this->add($fixture, $this->annotation())->toRevision();
                self::fail('P1/P2 must reject annotations.');
            } catch (\LogicException $error) {
                self::assertStringContainsString('P3', $error->getMessage());
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function attacks(): iterable
    {
        foreach (['content', 'page', 'orphan', 'fake-xref', 'remove-perms', 'remove-form', 'trailer', 'field-name', 'field-value'] as $attack) {
            yield $attack => [$attack];
        }
    }

    #[DataProvider('attacks')]
    public function testAnnotationPermissionDoesNotConcealOtherChanges(string $attack): void
    {
        $fixture = new ProtectedRevisionFixture(
            DocMdpPermissions::FormsTemplatesSigningAndAnnotations,
            lock: new \PDF\Document\Forms\SignatureFieldLock(\PDF\Document\Forms\SignatureLockAction::Include, new ArrayType(new TextString('name')))
        );
        $base = $this->add($fixture, $this->annotation());
        $mutation = $fixture->mutation($attack);
        $objects = [...$mutation->body->objects(), ...$base->body->objects()];
        if ($attack === 'page') {
            // Merge the two actual page mutations instead of letting a duplicate
            // object definition overwrite one another in fixture construction.
            $page = $fixture->revision->binding($fixture->page);
            $objects = [new IndirectObject($page->dictionary->with($this->entry('Annots', new ArrayType(new IndirectReference($this->annotation()))))->with($this->entry('Rotate', new IntegerType(90))), $page->identifier)];
        }
        $this->expectException(\LogicException::class);
        (new IncrementalUpdate($fixture->revision, new FileBody(...$objects), trailerEntries: $mutation->trailerEntries))->toRevision();
    }

    public function testSharedAnnotationAliasCannotBeChanged(): void
    {
        $fixture = new ProtectedRevisionFixture(DocMdpPermissions::FormsTemplatesSigningAndAnnotations);
        $annotation = $this->annotation();
        $update = $this->add($fixture, $annotation);
        $alias = new DictionaryType($this->entry('Hidden', new IndirectReference($annotation)));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('shared reference');
        (new IncrementalUpdate($fixture->revision, new FileBody(...[...$update->body->objects(), $alias])))->toRevision();
    }

    public function testExistingAppearanceBytesCannotBeMutatedUnderAnnotationPermission(): void
    {
        $fixture = new ProtectedRevisionFixture(DocMdpPermissions::FormsTemplatesSigningAndAnnotations);
        $appearance = $this->appearance();
        $annotation = $this->annotation($appearance);
        $added = $this->add($fixture, $annotation)->toRevision();
        $stream = $added->binding($appearance);
        $record = $added->binding($annotation);
        $this->expectException(\LogicException::class);
        (new IncrementalUpdate($added, new FileBody(
            new IndirectObject(new StreamObject('q 1 0 0 rg 0 0 20 20 re f Q', $appearance->dictionary()), $stream->identifier),
            new IndirectObject($record->dictionary->with($this->entry('Contents', new TextString('changed'))), $record->identifier),
        )))->toRevision();
    }

    /** @return iterable<string, array{string, BasicType}> */
    public static function unclassifiedEntries(): iterable
    {
        yield 'action' => ['A', new DictionaryType(new Entry(new NameType('S'), new NameType('JavaScript')), new Entry(new NameType('JS'), new TextString('arbitrary')))];
        yield 'additional-action' => ['AA', new DictionaryType()];
        yield 'file' => ['FS', new TextString('external')];
        yield 'structure' => ['StructParent', new IntegerType(1)];
        yield 'widget' => ['Subtype', new NameType('Widget')];
    }

    #[DataProvider('unclassifiedEntries')]
    public function testNewMarkupCannotCarryUnclassifiedCapabilities(string $key, BasicType $value): void
    {
        $fixture = new ProtectedRevisionFixture(DocMdpPermissions::FormsTemplatesSigningAndAnnotations);
        $this->expectException(\LogicException::class);
        $this->add($fixture, $this->annotation()->with($this->entry($key, $value)))->toRevision();
    }

    public function testNewAppearanceGetsFreshIdentityWhileOldBytesRemainUntouched(): void
    {
        $fixture = new ProtectedRevisionFixture(DocMdpPermissions::FormsTemplatesSigningAndAnnotations);
        $oldAppearance = $this->appearance();
        $annotation = $this->annotation($oldAppearance);
        $added = $this->add($fixture, $annotation)->toRevision();
        $record = $added->binding($annotation);
        $newAppearance = $this->appearance('q 1 0 0 rg 0 0 20 20 re f Q');
        $next = (new IncrementalUpdate($added, new FileBody(new IndirectObject(
            $record->dictionary->with($this->entry('AP', new DictionaryType($this->entry('N', new IndirectReference($newAppearance))))),
            $record->identifier,
        ))))->toRevision();
        self::assertSame($added->binding($oldAppearance)->snapshot, $next->binding($oldAppearance)->snapshot);
        self::assertGreaterThanOrEqual($added->size->integer(), $next->binding($newAppearance)->identifier->objectNumber->value);
    }

    private function add(ProtectedRevisionFixture $fixture, DictionaryType $annotation): IncrementalUpdate
    {
        $page = $fixture->revision->binding($fixture->page);
        return new IncrementalUpdate($fixture->revision, new FileBody(new IndirectObject($page->dictionary->with($this->entry('Annots', new ArrayType(new IndirectReference($annotation)))), $page->identifier)));
    }

    private function annotation(?StreamObject $appearance = null): DictionaryType
    {
        return new DictionaryType(
            $this->entry('Type', new NameType('Annot')),
            $this->entry('Subtype', new NameType('Square')),
            $this->entry('Rect', new Rectangle(new IntegerType(20), new IntegerType(20), new IntegerType(40), new IntegerType(40))),
            $this->entry('Contents', new TextString('review')),
            $this->entry('F', new IntegerType(4)),
            $this->entry('AP', new DictionaryType($this->entry('N', new IndirectReference($appearance ?? $this->appearance()))))
        );
    }

    private function appearance(string $bytes = 'q 0 0 1 rg 0 0 20 20 re f Q'): StreamObject
    {
        return new StreamObject($bytes, new DictionaryType(
            $this->entry('Type', new NameType('XObject')),
            $this->entry('Subtype', new NameType('Form')),
            $this->entry('BBox', new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(20), new IntegerType(20))),
            $this->entry('Resources', new DictionaryType())
        ));
    }

    private function entry(string $key, BasicType $value): Entry
    {
        return new Entry(new NameType($key), $value);
    }
}
