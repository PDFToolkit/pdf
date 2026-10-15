<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\Forms\SignatureFieldLock;
use PDF\Document\Forms\SignatureLockAction;
use PDF\Document\Signatures\PreparedSignature;
use PDF\Document\Signatures\Policies\DocMdpPermissions;
use PDF\Serialization\IncrementalWriter;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\FileStructure\CrossReferenceFormat;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileRevision;
use PDF\Syntax\FileStructure\IncrementalUpdate;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IndirectObject;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Tests\Support\ProtectedRevisionFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IncrementalSignaturePermissionsTest extends TestCase
{
    /** @return iterable<string,array{DocMdpPermissions,string}> */
    public static function mutations(): iterable
    {
        foreach (DocMdpPermissions::cases() as $permission) {
            foreach (['lang', 'remove-perms', 'remove-form', 'root', 'trailer', 'release', 'field-value', 'field-name', 'content', 'page', 'fake-xref', 'orphan'] as $attack) {
                yield $permission->name . ':' . $attack => [$permission, $attack];
            }
        }
    }

    #[DataProvider('mutations')]
    public function testOrdinaryWritesCannotBypassAnyHistoricalCertification(DocMdpPermissions $permission, string $attack): void
    {
        $fixture = new ProtectedRevisionFixture($permission);
        self::assertNotNull($fixture->revision->verifiedSignature);
        $this->expectException(\LogicException::class);
        $fixture->mutation($attack)->toRevision();
    }

    /** @return iterable<string,array{string}> */
    public static function entryPoints(): iterable
    {
        foreach (['revision', 'pdf', 'string', 'write', 'writer', 'emit'] as $entry) {
            yield $entry => [$entry];
        }
    }

    #[DataProvider('entryPoints')]
    public function testAllPublicIncrementalEntryPointsShareTheGuard(string $entry): void
    {
        $update = (new ProtectedRevisionFixture())->mutation('lang');
        $path = sys_get_temp_dir() . '/pdftoolkit-protected-' . bin2hex(random_bytes(8)) . '.pdf';
        try {
            match ($entry) {
                'revision' => $update->toRevision(),
                'pdf' => $update->toPdf(),
                'string' => (string) $update,
                'write' => $update->writeTo($path),
                'writer' => (new IncrementalWriter())->serialize($update),
                'emit' => (new IncrementalWriter())->emit($update, (new \PDF\Serialization\NativeRevisionEmission(new IncrementalWriter(), (new ProtectedRevisionFixture())->dss()))),
            };
            self::fail('The changed catalog must never be emitted.');
        } catch (\LogicException) {
            self::assertFileDoesNotExist($path);
        }
    }

    /** @return iterable<string,array{DocMdpPermissions,CrossReferenceFormat}> */
    public static function permittedApprovals(): iterable
    {
        foreach ([DocMdpPermissions::FormFillingTemplatesAndSigning, DocMdpPermissions::FormsTemplatesSigningAndAnnotations] as $permission) {
            foreach (CrossReferenceFormat::cases() as $format) {
                yield $permission->name . ':' . $format->name => [$permission, $format];
            }
        }
    }

    #[DataProvider('permittedApprovals')]
    public function testActualVerifiedNativeApprovalStillWorks(DocMdpPermissions $permission, CrossReferenceFormat $format): void
    {
        $fixture = new ProtectedRevisionFixture($permission, format: $format);
        [$update, $pending] = $fixture->signing();
        $prepared = new PreparedSignature($update->toRevision(), $pending);
        $signed = $prepared->signWithPolicy($fixture->backend);
        self::assertStringStartsWith($fixture->revision->bytes, $signed->revision->bytes);
        self::assertNotNull($signed->revision->verifiedSignature);
        $signed->revision->verifiedSignature->validateBinding($signed->revision);
        $this->expectException(\LogicException::class);
        $catalog = $signed->revision->binding($fixture->catalog);
        (new IncrementalUpdate($signed->revision, new FileBody(new IndirectObject($catalog->dictionary->with(new Entry(new NameType('Lang'), new TextString('changed'))), $catalog->identifier))))->toRevision();
    }

    #[DataProvider('permittedApprovals')]
    public function testProvisionalSignatureCannotCarryUnrelatedChanges(DocMdpPermissions $permission, CrossReferenceFormat $format): void
    {
        $fixture = new ProtectedRevisionFixture($permission, format: $format);
        [$update] = $fixture->signing(attack: 'content');
        $this->expectException(\LogicException::class);
        $update->toRevision();
    }

    /** @return iterable<string,array{DocMdpPermissions,string}> */
    public static function timestampMutations(): iterable
    {
        foreach (self::mutations() as $name => [$permission, $attack]) {
            if (!in_array($attack, ['release', 'trailer', 'root'], true)) {
                yield $name => [$permission, $attack];
            }
        }
    }

    #[DataProvider('timestampMutations')]
    public function testProvisionalTimestampDoesNotHideUnrelatedChanges(DocMdpPermissions $permission, string $attack): void
    {
        $fixture = new ProtectedRevisionFixture($permission);
        [$update] = $fixture->signing(timestamp: true, attack: $attack);
        $this->expectException(\LogicException::class);
        $update->toRevision();
    }

    public function testOpaqueSignedRevisionIsNeverVerificationAuthority(): void
    {
        $fixture = new ProtectedRevisionFixture(opaque: true);
        self::assertTrue($fixture->revision->isNativeEmission());
        self::assertNull($fixture->revision->verifiedSignature);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('mathematical verification');
        $fixture->dss()->toRevision();
    }

    public function testCallerSuppliedAndClonedRevisionsAreNotNativeHistory(): void
    {
        $original = (new ProtectedRevisionFixture())->revision;
        $manual = new FileRevision($original->bytes, $original->header, $original->crossReferenceOffset, $original->size, $original->root, $original->trailerEntries, $original->crossReferenceFormat, $original->freeListHead, ...[...$original->objects(), ...$original->spans(), ...$original->references(), $original->freeList]);
        foreach ([$manual, clone $original] as $revision) {
            self::assertFalse($revision->isNativeEmission());
            try {
                (new IncrementalUpdate($revision, new FileBody()))->toRevision();
                self::fail('A supplied snapshot cannot acquire native provenance.');
            } catch (\LogicException $error) {
                self::assertStringContainsString('native emitted revision', $error->getMessage());
            }
        }
    }

    public function testNativeProvenanceAndMathematicalEvidenceCannotBeRestoredFromPhpData(): void
    {
        $revision = (new ProtectedRevisionFixture())->revision;
        foreach ([$revision, $revision->verifiedSignature] as $value) {
            try {
                serialize($value);
                self::fail('Native evidence must not be reconstructed without executing emission and verification.');
            } catch (\LogicException $error) {
                self::assertStringContainsString('cannot be serialized', $error->getMessage());
            }
        }
    }

    public function testDssOnlyMaintenanceKeepsFieldLockP1Binding(): void
    {
        $fixture = new ProtectedRevisionFixture(lock: new SignatureFieldLock(SignatureLockAction::Include, new ArrayType(), new IntegerType(1)));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('field lock P1 remains binding');
        $fixture->dss()->toRevision();
    }

    public function testActualDssMaintenancePreservesOriginalPolicyForFollowingWrites(): void
    {
        $fixture = new ProtectedRevisionFixture();
        $maintenance = $fixture->dss()->toRevision();
        self::assertStringStartsWith($fixture->revision->bytes, $maintenance->bytes);
        $catalog = $maintenance->binding($fixture->catalog);
        $this->expectException(\LogicException::class);
        (new IncrementalUpdate($maintenance, new FileBody(new IndirectObject($catalog->dictionary->without('Perms')->without('AcroForm'), $catalog->identifier))))->toRevision();
    }

    public function testFieldMdpAloneProtectsNonSignatureFieldValues(): void
    {
        $fixture = new ProtectedRevisionFixture(null, new SignatureFieldLock(SignatureLockAction::Include, new ArrayType(new TextString('name'))));
        $this->expectException(\LogicException::class);
        $fixture->mutation('field-value')->toRevision();
    }

    public function testFieldMdpAloneDoesNotLetTimestampCarryPageChanges(): void
    {
        $fixture = new ProtectedRevisionFixture(null, new SignatureFieldLock(SignatureLockAction::Include, new ArrayType(new TextString('name'))));
        [$update] = $fixture->signing(timestamp: true, attack: 'page');
        $this->expectException(\LogicException::class);
        $update->toRevision();
    }

    public function testUnprotectedPrivateKeysDoNotInventASignature(): void
    {
        $private = new \PDF\Syntax\Object\DictionaryType(new Entry(new NameType('Contents'), new TextString('annotation text')), new Entry(new NameType('ByteRange'), new TextString('private extension data')));
        $catalog = new \PDF\Syntax\DocumentStructure\DocumentCatalog(new \PDF\Syntax\DocumentStructure\PageTree\PageTreeNode());
        $revision = (new \PDF\FileStructure(new \PDF\Syntax\FileStructure\FileHeader(), new FileBody($catalog, $private)))->toRevision();
        $following = (new IncrementalUpdate($revision, new FileBody()))->toRevision();
        self::assertStringStartsWith($revision->bytes, $following->bytes);
    }
}
