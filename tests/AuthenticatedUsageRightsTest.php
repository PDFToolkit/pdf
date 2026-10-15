<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\Forms\AcroForm;
use PDF\Document\Forms\DefaultAppearance;
use PDF\Document\Forms\FieldTree;
use PDF\Document\Forms\PartialFieldName;
use PDF\Document\Forms\TextField;
use PDF\Document\ResourceDictionary;
use PDF\Document\Signatures\PendingSignature;
use PDF\Document\Signatures\SignatureCapacity;
use PDF\Document\Signatures\SignatureFormat;
use PDF\Document\Signatures\UsageRightsAuthority;
use PDF\Document\Signatures\UsageRightsAuthorization;
use PDF\Document\Signatures\VerifiedRevisionSignature;
use PDF\Document\Signatures\Policies\SignatureReference;
use PDF\Document\Signatures\Policies\SignatureReferences;
use PDF\Document\Signatures\Policies\UsageRight;
use PDF\Document\Signatures\Policies\UsageRightsMode;
use PDF\Document\Signatures\Policies\UsageRightsTransform;
use PDF\FileStructure;
use PDF\Serialization\SignatureWriter;
use PDF\Syntax\ContentStreamsResources\Operators\SetTextFontAndSize;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\FileStructure\FileRevision;
use PDF\Syntax\FileStructure\IncrementalUpdate;
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

final class AuthenticatedUsageRightsTest extends TestCase
{
    /** @return iterable<string, array{list<UsageRight>, bool, string, bool}> */
    public static function saving(): iterable
    {
        yield 'restrictive ISO1 fill' => [[UsageRight::FillField], true, '1.7', true];
        yield 'restrictive ISO2 fill requires save' => [[UsageRight::FillField], true, '2.0', false];
        yield 'restrictive ISO2 explicit save' => [[UsageRight::FillField, UsageRight::FullSave], true, '2.0', true];
        yield 'ISO1 explicit save' => [[UsageRight::FillField, UsageRight::FullSave], false, '1.7', true];
        yield 'ISO1 fill without save' => [[UsageRight::FillField], false, '1.7', false];
        yield 'unrelated annotation save' => [[UsageRight::FillField, UsageRight::CreateAnnotation], false, '1.7', false];
        yield 'FullSave without fill' => [[UsageRight::FullSave], true, '1.7', false];
        yield 'missing signed right' => [[UsageRight::ModifySignature], true, '1.7', false];
        yield 'ISO2 P absent is unrestricted' => [[UsageRight::FillField, UsageRight::FullSave], false, '2.0', true];
        yield 'ISO2 unrestricted without named grants' => [[], false, '2.0', true];
        yield 'ISO2 restrictive without named fill' => [[UsageRight::FullSave], true, '2.0', false];
    }

    #[DataProvider('saving')]
    public function testVerifiedRightsAreIntersectedWithActualOperationAndSaving(array $rights, bool $restrict, string $version, bool $allowed): void
    {
        [$revision, $field] = $this->fixture($rights, $restrict, $version);
        $authorization = $this->authorization($revision, UsageRight::FillField);
        if (!$allowed) {
            $this->expectException(\LogicException::class);
        }
        $next = $this->fill($revision, $field, $authorization)->toRevision();
        self::assertStringStartsWith($revision->bytes, $next->bytes);
        self::assertSame('changed', $next->binding($field)->dictionary->get('V')->value);
    }

    public function testMathematicallyValidSignatureAloneDoesNotGrantUsageRights(): void
    {
        [$revision, $field] = $this->fixture([UsageRight::FillField]);
        self::assertNotNull($revision->verifiedSignature);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('explicit granting authority');
        $this->fill($revision, $field)->toRevision();
    }

    public function testUnrelatedExplicitScopeDoesNotGrantFillIn(): void
    {
        [$revision, $field] = $this->fixture([UsageRight::FillField, UsageRight::ModifyAnnotation]);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('explicit application scope');
        $this->fill($revision, $field, $this->authorization($revision, UsageRight::ModifyAnnotation))->toRevision();
    }

    public function testAuthorityReceivesActualVerifiedCertificateAndCanRefuse(): void
    {
        [$revision, $field] = $this->fixture([UsageRight::FillField]);
        $authority = new class ($revision->verifiedSignature) implements UsageRightsAuthority {
            public bool $called = false;
            public function __construct(private VerifiedRevisionSignature $expected)
            {
            }
            public function permits(VerifiedRevisionSignature $signature): bool
            {
                self::assertSameIdentity($this->expected, $signature);
                $this->called = true;
                return false;
            }
            private static function assertSameIdentity(VerifiedRevisionSignature $expected, VerifiedRevisionSignature $actual): void
            {
                TestCase::assertSame($expected, $actual);
                TestCase::assertSame($expected->facts->certificate->certificate()->value, $actual->facts->certificate->certificate()->value);
            }
        };
        try {
            $this->fill($revision, $field, new UsageRightsAuthorization($authority, UsageRight::FillField))->toRevision();
            self::fail('A refused granting identity must stop writing.');
        } catch (\LogicException $error) {
            self::assertTrue($authority->called);
            self::assertStringContainsString('not an authorized', $error->getMessage());
        }
    }

    public function testAuthorityErrorsDoNotExposeCallbackSecrets(): void
    {
        [$revision, $field] = $this->fixture([UsageRight::FillField]);
        $authority = new class () implements UsageRightsAuthority {
            public function permits(VerifiedRevisionSignature $signature): bool
            {
                throw new \RuntimeException('private application secret');
            }
        };
        try {
            $this->fill($revision, $field, new UsageRightsAuthorization($authority, UsageRight::FillField))->toRevision();
            self::fail('Authority failure must reject the update.');
        } catch (\LogicException $error) {
            self::assertStringNotContainsString('private application secret', $error->getMessage());
            self::assertNull($error->getPrevious());
        }
    }

    public function testExplicitAuthorizationBelongsToEachUpdateNotToTheDocument(): void
    {
        [$revision, $field] = $this->fixture([UsageRight::FillField]);
        $next = $this->fill($revision, $field, $this->authorization($revision, UsageRight::FillField))->toRevision();
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('explicit granting authority');
        $this->fill($next, $field)->toRevision();
    }

    public function testRemovingDeclaredPermissionsCannotEraseHistoricalUsagePolicy(): void
    {
        [$revision, $field, $catalog] = $this->fixture([UsageRight::FillField]);
        $filled = $this->fill($revision, $field, $this->authorization($revision, UsageRight::FillField));
        $record = $revision->binding($catalog);
        $this->expectException(\LogicException::class);
        (new IncrementalUpdate($revision, new FileBody(...[...$filled->body->objects(),
            new IndirectObject($record->dictionary->without('Perms'), $record->identifier)]), null, null, $filled->usageRights))->toRevision();
    }

    public function testAnAuthorizedFillStillCannotChangePageContent(): void
    {
        [$revision, $field, , $content] = $this->fixture([UsageRight::FillField, UsageRight::FullSave]);
        $filled = $this->fill($revision, $field, $this->authorization($revision, UsageRight::FillField, UsageRight::FullSave));
        $record = $revision->binding($content);
        $this->expectException(\LogicException::class);
        (new IncrementalUpdate($revision, new FileBody(...[...$filled->body->objects(), new IndirectObject(new StreamObject('0 0 10 10 re f'), $record->identifier)]), null, null, $filled->usageRights))->toRevision();
    }

    public function testOpaqueSignatureCannotAcquireGrantingAuthority(): void
    {
        [$revision, $field] = $this->fixture([UsageRight::FillField], opaque: true);
        $authority = new class () implements UsageRightsAuthority {
            public function permits(VerifiedRevisionSignature $signature): bool
            {
                TestCase::fail('Authority must never be consulted before mathematical verification.');
            }
        };
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('mathematical verification');
        $this->fill($revision, $field, new UsageRightsAuthorization($authority, UsageRight::FillField))->toRevision();
    }

    public function testUsageRightsNeverOverrideHistoricalFieldMdp(): void
    {
        [$revision, $field] = $this->fixture(
            [UsageRight::FillField, UsageRight::ModifySignature],
            lock: new \PDF\Document\Forms\SignatureFieldLock(\PDF\Document\Forms\SignatureLockAction::Include, new \PDF\Syntax\Object\ArrayType(new TextString('value')))
        );
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('FieldMDP');
        $this->fill($revision, $field, $this->authorization($revision, UsageRight::FillField))->toRevision();
    }

    public function testUsageRightsNeverOverrideHistoricalP1(): void
    {
        [$revision, $field] = $this->fixture([UsageRight::FillField, UsageRight::ModifySignature], certification: \PDF\Document\Signatures\Policies\DocMdpPermissions::NoChanges);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('P1');
        $this->fill($revision, $field, $this->authorization($revision, UsageRight::FillField))->toRevision();
    }

    public function testUsageRightsAndP2PermitOnlyTheSameClassifiedFill(): void
    {
        [$revision, $field] = $this->fixture([UsageRight::FillField, UsageRight::ModifySignature], certification: \PDF\Document\Signatures\Policies\DocMdpPermissions::FormFillingTemplatesAndSigning);
        $next = $this->fill($revision, $field, $this->authorization($revision, UsageRight::FillField))->toRevision();
        self::assertStringStartsWith($revision->bytes, $next->bytes);
    }

    public function testDefaultTransformVersionAndExplicitFalseAreReadFromSignedBytes(): void
    {
        [$revision, $field] = $this->fixture([], false, '2.0', omitVersion: true, explicitFalse: true);
        $next = $this->fill($revision, $field, $this->authorization($revision, UsageRight::FillField))->toRevision();
        self::assertStringStartsWith($revision->bytes, $next->bytes);
        self::assertSame('changed', $next->binding($field)->dictionary->get('V')->value);
    }

    public function testUnrestrictedPdf20StillRequiresPerUpdateAuthorityAndScope(): void
    {
        [$revision, $field] = $this->fixture([], false, '2.0');
        try {
            $this->fill($revision, $field)->toRevision();
            self::fail('Unrestricted UR does not manufacture a granting authority.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('explicit granting authority', $error->getMessage());
        }
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('explicit application scope');
        $this->fill($revision, $field, $this->authorization($revision, UsageRight::ModifyAnnotation))->toRevision();
    }

    public function testUnrestrictedPdf20DoesNotOverrideHistoricalDocMdp(): void
    {
        [$revision, $field] = $this->fixture([], false, '2.0', certification: \PDF\Document\Signatures\Policies\DocMdpPermissions::NoChanges);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('P1');
        $this->fill($revision, $field, $this->authorization($revision, UsageRight::FillField))->toRevision();
    }

    public function testUnrestrictedPdf20DoesNotOverrideHistoricalFieldMdp(): void
    {
        [$revision, $field] = $this->fixture([], false, '2.0', lock: new \PDF\Document\Forms\SignatureFieldLock(\PDF\Document\Forms\SignatureLockAction::All));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('FieldMDP');
        $this->fill($revision, $field, $this->authorization($revision, UsageRight::FillField))->toRevision();
    }

    public function testAnnotationRightsAreRequiredForEachActualOperation(): void
    {
        [$revision, , , , $page] = $this->fixture([UsageRight::CreateAnnotation, UsageRight::ModifyAnnotation, UsageRight::DeleteAnnotation]);
        $annotation = new DictionaryType(
            $this->entry('Type', new NameType('Annot')),
            $this->entry('Subtype', new NameType('Square')),
            $this->entry('Rect', new Rectangle(new IntegerType(10), new IntegerType(10), new IntegerType(30), new IntegerType(30))),
            $this->entry('Contents', new TextString('first')),
        );
        $pageRecord = $revision->binding($page);
        $created = (new IncrementalUpdate($revision, new FileBody(new IndirectObject(
            $pageRecord->dictionary->with($this->entry('Annots', new \PDF\Syntax\Object\ArrayType(new IndirectReference($annotation)))),
            $pageRecord->identifier
        )), null, null, $this->authorization($revision, UsageRight::CreateAnnotation)))->toRevision();
        $record = $created->binding($annotation);
        $modification = new FileBody(new IndirectObject($record->dictionary->with($this->entry('Contents', new TextString('second'))), $record->identifier));
        try {
            (new IncrementalUpdate($created, $modification, null, null, $this->authorization($revision, UsageRight::CreateAnnotation)))->toRevision();
            self::fail('Create scope cannot authorize an actual modification.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('explicit application scope', $error->getMessage());
        }
        $modified = (new IncrementalUpdate($created, $modification, null, null, $this->authorization($revision, UsageRight::ModifyAnnotation)))->toRevision();
        self::assertSame('second', $modified->binding($annotation)->dictionary->get('Contents')->value);
        $pageRecord = $modified->binding($page);
        $deletion = new FileBody(new IndirectObject($pageRecord->dictionary->without('Annots'), $pageRecord->identifier));
        try {
            (new IncrementalUpdate($modified, $deletion, null, null, $this->authorization($revision, UsageRight::ModifyAnnotation)))->toRevision();
            self::fail('Modify scope cannot authorize an actual deletion.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('explicit application scope', $error->getMessage());
        }
        $deleted = (new IncrementalUpdate($modified, $deletion, null, null, $this->authorization($revision, UsageRight::DeleteAnnotation)))->toRevision();
        self::assertNull($deleted->binding($page)->dictionary->get('Annots'));
        self::assertSame('second', $deleted->binding($annotation)->dictionary->get('Contents')->value);
        self::assertStringStartsWith($revision->bytes, $deleted->bytes);
    }

    /** @param list<UsageRight> $rights
     * @return array{FileRevision, TextField, DocumentCatalog, StreamObject, PageObject}
     */
    private function fixture(
        array $rights,
        bool $restrict = true,
        string $version = '1.7',
        bool $opaque = false,
        ?\PDF\Document\Forms\SignatureFieldLock $lock = null,
        ?\PDF\Document\Signatures\Policies\DocMdpPermissions $certification = null,
        bool $omitVersion = false,
        bool $explicitFalse = false
    ): array {
        $backend = (new ProtectedRevisionFixture())->backend;
        $transform = new UsageRightsTransform(UsageRightsMode::ByteRange, $restrict, null, ...$rights);
        $reference = new SignatureReference($transform);
        $parameters = new DictionaryType(...iterator_to_array($transform->entries(), false));
        if ($omitVersion) {
            $parameters = $parameters->without('V');
        }
        if ($explicitFalse) {
            $parameters = $parameters->with($this->entry('P', new \PDF\Syntax\Object\BooleanType(false)));
        }
        $reference = ($omitVersion || $explicitFalse)
            ? (new DictionaryType(...iterator_to_array($reference->entries(), false)))->with($this->entry('TransformParams', $parameters))
            : $reference;
        $pending = new PendingSignature(
            new SignatureCapacity(8192),
            SignatureFormat::Pkcs7Detached,
            entries: $this->entry('Reference', new \PDF\Syntax\Object\ArrayType($reference))
        );
        $field = new TextField(new PartialFieldName(new TextString('value')), value: new TextString('original'));
        $font = require dirname(__DIR__) . '/examples/fixtures/pdf20-courier-font.php';
        $resources = new DictionaryType($this->entry('Font', new DictionaryType($this->entry('F', new IndirectReference($font)))));
        $tree = (new FieldTree(new DefaultAppearance(new SetTextFontAndSize(new NameType('F'), new IntegerType(12))), $resources))->addRoot($field);
        $author = ($lock !== null || $certification !== null) ? new \PDF\Document\Signatures\SignatureFieldSigning(
            $backend,
            new PartialFieldName(new TextString('author')),
            new SignatureCapacity(8192),
            new \PDF\Document\Signatures\SignatureSigningIntent(certification: $certification),
            lock: $lock
        ) : null;
        if ($author !== null) {
            $tree->addRoot($author->field);
        }
        $form = new AcroForm($tree);
        $content = new StreamObject('q Q');
        $page = new PageObject(new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(200), new IntegerType(200)), new ResourceDictionary(), $content);
        $catalog = new DocumentCatalog(
            (new PageTreeNode())->addKid($page),
            $this->entry('AcroForm', new IndirectReference($form)),
            $this->entry('Perms', new DictionaryType($this->entry('UR3', new IndirectReference($pending))))
        );
        $author?->bindCatalog(new IndirectReference($catalog));
        $prepared = (new SignatureWriter())->prepare(new FileStructure(new FileHeader($version), new FileBody($catalog)), $pending);
        $signed = $opaque ? $prepared->finalize(new \PDF\Document\Signatures\SignatureValue('opaque')) : $prepared->sign($backend);
        $revision = $signed->revision;
        if ($author !== null) {
            $record = $revision->binding($author->signature);
            $update = new IncrementalUpdate(
                $revision,
                new FileBody(new IndirectObject($author->signature, $record->identifier)),
                null,
                null,
                $this->authorization($revision, UsageRight::ModifySignature)
            );
            $revision = $author->sign(new \PDF\Document\Signatures\PreparedSignature($update->toRevision(), $author->signature))->revision;
        }
        return [$revision, $field, $catalog, $content, $page];
    }

    private function authorization(FileRevision $revision, UsageRight ...$rights): UsageRightsAuthorization
    {
        $fingerprint = hash('sha256', $revision->verifiedSignature->facts->certificate->certificate()->value);
        $authority = new class ($fingerprint) implements UsageRightsAuthority {
            public function __construct(private string $fingerprint)
            {
            }
            public function permits(VerifiedRevisionSignature $signature): bool
            {
                return hash_equals($this->fingerprint, hash('sha256', $signature->facts->certificate->certificate()->value));
            }
        };
        return new UsageRightsAuthorization($authority, ...$rights);
    }

    private function fill(FileRevision $revision, TextField $field, ?UsageRightsAuthorization $authorization = null): IncrementalUpdate
    {
        $record = $revision->binding($field);
        return new IncrementalUpdate($revision, new FileBody(new IndirectObject($record->dictionary->with($this->entry('V', new TextString('changed'))), $record->identifier)), null, null, ...($authorization === null ? [] : [$authorization]));
    }

    private function entry(string $key, BasicType $value): Entry
    {
        return new Entry(new NameType($key), $value);
    }
}
