<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\Forms\AcroForm;
use PDF\Document\Forms\FieldTree;
use PDF\Document\Forms\NamedPageTemplate;
use PDF\Document\Forms\PartialFieldName;
use PDF\Document\ResourceDictionary;
use PDF\Document\Signatures\PageTemplateInstantiation;
use PDF\Document\Signatures\Policies\DocMdpPermissions;
use PDF\Document\Signatures\SignatureCapacity;
use PDF\Document\Signatures\SignatureFieldSigning;
use PDF\Document\Signatures\SignatureSigningIntent;
use PDF\FileStructure;
use PDF\Serialization\SignatureWriter;
use PDF\Syntax\DataStructure\NameTree;
use PDF\Syntax\DataStructure\NameTreeEntry;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\CrossReferenceFormat;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
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

final class ProtectedPageTemplatesTest extends TestCase
{
    /** @return iterable<string, array{DocMdpPermissions, CrossReferenceFormat, string}> */
    public static function combinations(): iterable
    {
        foreach (['1.7', '2.0'] as $version) {
            foreach ([DocMdpPermissions::FormFillingTemplatesAndSigning, DocMdpPermissions::FormsTemplatesSigningAndAnnotations] as $permissions) {
                foreach (CrossReferenceFormat::cases() as $format) {
                    yield $version . ':' . $permissions->value . ':' . $format->name => [$permissions, $format, $version];
                }
            }
        }
    }

    #[DataProvider('combinations')]
    public function testInstantiationPreservesTemplateResourcesOriginalPagesAndSignedBytes(DocMdpPermissions $permissions, CrossReferenceFormat $format, string $version): void
    {
        [$revision, $template, $contents] = $this->fixture($permissions, $format, $version);
        $operation = new PageTemplateInstantiation($revision, new NameType('Invoice'));
        $next = $operation->update()->toRevision();
        self::assertStringStartsWith($revision->bytes, $next->bytes);
        self::assertSame(2, $next->binding($next->pageTreeIdentifier)->dictionary->get('Count')->integer());
        self::assertSame('Invoice', $next->binding($operation->page)->dictionary->get('TemplateInstantiated')->value);
        self::assertSame('Template', $next->binding($template)->dictionary->get('Type')->value);
        self::assertSame($revision->binding($contents)->snapshot->encodedBytes, $next->binding($contents)->snapshot->encodedBytes);
        $again = (new PageTemplateInstantiation($next, new NameType('Invoice')))->update()->toRevision();
        self::assertSame(3, $again->binding($again->pageTreeIdentifier)->dictionary->get('Count')->integer());
        self::assertStringStartsWith($next->bytes, $again->bytes);
    }

    public function testP1DoesNotAllowTemplateSpawning(): void
    {
        [$revision] = $this->fixture(DocMdpPermissions::NoChanges);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('P1');
        (new PageTemplateInstantiation($revision, new NameType('Invoice')))->update()->toRevision();
    }

    public function testDeclaredTemplateNameCannotAuthorizeDifferentPageContent(): void
    {
        [$revision] = $this->fixture();
        $operation = new PageTemplateInstantiation($revision, new NameType('Invoice'));
        $page = $operation->page->with(new Entry(new NameType('Contents'), new IndirectReference(new StreamObject('arbitrary new content'))));
        $root = $revision->binding($revision->pageTreeIdentifier);
        $kids = $root->dictionary->get('Kids');
        $replacement = $root->dictionary->with(new Entry(new NameType('Kids'), new ArrayType(...[...$kids->items, new IndirectReference($page)])))
            ->with(new Entry(new NameType('Count'), new IntegerType(2)));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('exact template');
        (new IncrementalUpdate($revision, new FileBody(new IndirectObject($replacement, $root->identifier))))->toRevision();
    }

    public function testTemplateContentCannotBeChangedWhileCreatingItsInstance(): void
    {
        [$revision, , $contents] = $this->fixture();
        $operation = new PageTemplateInstantiation($revision, new NameType('Invoice'));
        $record = $revision->binding($contents);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('outside its classified');
        (new IncrementalUpdate($revision, new FileBody(...[...$operation->body->objects(), new IndirectObject(new StreamObject('changed'), $record->identifier)])))->toRevision();
    }

    public function testAddingATemplateCannotReorderHistoricalPages(): void
    {
        [$revision] = $this->fixture();
        $two = (new PageTemplateInstantiation($revision, new NameType('Invoice')))->update()->toRevision();
        $third = new PageTemplateInstantiation($two, new NameType('Invoice'));
        $root = $two->binding($two->pageTreeIdentifier);
        $kids = $root->dictionary->get('Kids');
        $replacement = $root->dictionary->with(new Entry(new NameType('Kids'), new ArrayType($kids->items[1], $kids->items[0], new IndirectReference($third->page))))
            ->with(new Entry(new NameType('Count'), new IntegerType(3)));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('existing page order');
        (new IncrementalUpdate($two, new FileBody(new IndirectObject($replacement, $root->identifier))))->toRevision();
    }

    public function testTemplateSpawningDoesNotRelaxPageTreeInheritance(): void
    {
        [$revision] = $this->fixture();
        $operation = new PageTemplateInstantiation($revision, new NameType('Invoice'));
        $root = $revision->binding($revision->pageTreeIdentifier);
        $replacement = $root->dictionary
            ->with(new Entry(new NameType('Kids'), new ArrayType(...[...$root->dictionary->get('Kids')->items, new IndirectReference($operation->page)])))
            ->with(new Entry(new NameType('Count'), new IntegerType(2)))
            ->with(new Entry(new NameType('Rotate'), new IntegerType(90)));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('page-tree properties and inheritance');
        (new IncrementalUpdate($revision, new FileBody(new IndirectObject($replacement, $root->identifier))))->toRevision();
    }

    public function testMissingTemplateDoesNotCreateAnUnprovedPage(): void
    {
        [$revision] = $this->fixture();
        $this->expectException(\OutOfBoundsException::class);
        new PageTemplateInstantiation($revision, new NameType('Missing'));
    }

    public function testAddingAnOrphanAlongsideAValidInstanceIsRefused(): void
    {
        [$revision] = $this->fixture();
        $operation = new PageTemplateInstantiation($revision, new NameType('Invoice'));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('unclassified or orphan');
        (new IncrementalUpdate($revision, new FileBody(...[...$operation->body->objects(), new StreamObject('orphan')])))->toRevision();
    }

    /** @return array{FileRevision, NamedPageTemplate, StreamObject} */
    private function fixture(DocMdpPermissions $permissions = DocMdpPermissions::FormFillingTemplatesAndSigning, CrossReferenceFormat $format = CrossReferenceFormat::Table, string $version = '2.0'): array
    {
        $backend = (new ProtectedRevisionFixture())->backend;
        $author = new SignatureFieldSigning($backend, new PartialFieldName(new TextString('author')), new SignatureCapacity(8192), new SignatureSigningIntent(certification: $permissions));
        $box = new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(200), new IntegerType(200));
        $contents = new StreamObject('q 0 0 1 rg 20 20 40 40 re f Q');
        $template = new NamedPageTemplate($box, new ResourceDictionary(), $contents);
        $page = new PageObject($box, new ResourceDictionary(), new StreamObject('q Q'));
        $catalog = new DocumentCatalog(
            (new PageTreeNode())->addKid($page),
            new Entry(new NameType('AcroForm'), new IndirectReference(new AcroForm((new FieldTree())->addRoot($author->field)))),
            new Entry(new NameType('Perms'), $author->certificationPermissions()),
            new Entry(new NameType('Names'), new DictionaryType(new Entry(new NameType('Templates'), new NameTree(new NameTreeEntry(new TextString('Invoice'), new IndirectReference($template))))))
        );
        $author->bindCatalog(new IndirectReference($catalog));
        $revision = $author->sign((new SignatureWriter())->prepare(new FileStructure(new FileHeader($version), new FileBody($catalog), crossReferenceFormat: $format), $author->signature))->revision;
        return [$revision, $template, $contents];
    }
}
