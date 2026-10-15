<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\Forms\AcroForm;
use PDF\Document\Forms\FieldTree;
use PDF\Document\Forms\NonTerminalField;
use PDF\Document\Forms\PartialFieldName;
use PDF\Document\Forms\SignatureField;
use PDF\Document\Forms\SignatureFieldLock;
use PDF\Document\Forms\SignatureLockAction;
use PDF\Document\Signatures\FrozenSignatureGraph;
use PDF\Document\Signatures\PendingSignature;
use PDF\Document\Signatures\PreparedSignature;
use PDF\Document\Signatures\SignatureCapacity;
use PDF\Document\Signatures\SignatureFieldPolicyContract;
use PDF\Document\Signatures\SignatureFieldSigning;
use PDF\Document\Signatures\SignatureFormat;
use PDF\Document\Signatures\SignatureSigningIntent;
use PDF\Document\Signatures\Policies\DocMdpPermissions;
use PDF\Document\Signatures\Policies\FieldMdpTransform;
use PDF\Document\Signatures\Policies\SignatureDataReference;
use PDF\Document\Signatures\Policies\SignatureReference;
use PDF\Document\Signatures\Policies\SignatureReferences;
use PDF\Document\Signatures\Policies\UsageRightsTransform;
use PDF\Document\Signatures\Policies\UsageRightsMode;
use PDF\FileStructure;
use PDF\Security\CertificateChain;
use PDF\Security\OpenSslIdentity;
use PDF\Security\OpenSslSignatureBackend;
use PDF\Security\X509Certificate;
use PDF\Serialization\SignatureWriter;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\FileStructure\IncrementalUpdate;
use PDF\Syntax\FileStructure\ReleaseIndirectObject;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SignaturePermissionHistoryTest extends TestCase
{
    private function entry(string $key, BasicType $value): Entry
    {
        return new Entry(new NameType($key), $value);
    }
    private function backend(): OpenSslSignatureBackend
    {
        return new OpenSslSignatureBackend(new OpenSslIdentity(new CertificateChain(new X509Certificate(new HexadecimalString(file_get_contents(__DIR__ . '/Fixtures/signing/signer.der')))), file_get_contents(__DIR__ . '/Fixtures/signing/signer-private-key.pem')));
    }
    private function operation(string $name, ?SignatureFieldLock $lock = null, ?DocMdpPermissions $certification = null): SignatureFieldSigning
    {
        return new SignatureFieldSigning($this->backend(), new PartialFieldName(new TextString($name)), new SignatureCapacity(8192), new SignatureSigningIntent(certification: $certification), lock: $lock);
    }
    private function extensions(): Entry
    {
        return $this->entry('Extensions', new DictionaryType($this->entry('ADBE', new DictionaryType($this->entry('BaseVersion', new NameType('1.7')), $this->entry('ExtensionLevel', new IntegerType(3))))));
    }
    private function following(SignatureFieldSigning $first, SignatureFieldSigning $second, bool $group = false): PreparedSignature
    {
        $root = $group ? (new NonTerminalField(new PartialFieldName(new TextString('group'))))->addField($second->field) : $second->field;
        $tree = (new FieldTree())->addRoot($first->field)->addRoot($root);
        $form = new AcroForm($tree);
        $entries = [$this->entry('AcroForm', new IndirectReference($form)), $this->extensions()];
        if ($first->certificationPermissions() !== null) {
            $entries[] = $this->entry('Perms', $first->certificationPermissions());
        }
        $catalog = new DocumentCatalog(new PageTreeNode(), ...$entries);
        $first->bindCatalog(new IndirectReference($catalog));
        $second->bindCatalog(new IndirectReference($catalog));
        $signed = $first->sign((new SignatureWriter())->prepare(new FileStructure(new FileHeader(), new FileBody($catalog)), $first->signature));
        $revision = (new IncrementalUpdate($signed->revision, new FileBody($second->signature)))->toRevision();
        return (new SignatureWriter())->prepareRevision($revision, $second->signature);
    }

    public function testActualHistoricalDocMdpP1ForbidsSigningEvenAnAlreadyExistingSecondField(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Historical DocMDP/Adobe P1');
        $this->following($this->operation('author', certification: DocMdpPermissions::NoChanges), $this->operation('approval'));
    }
    public function testActualHistoricalAdobeP1ForbidsSigningALaterField(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Historical DocMDP/Adobe P1');
        $this->following($this->operation('author', new SignatureFieldLock(SignatureLockAction::Include, new ArrayType(), new IntegerType(1))), $this->operation('approval'));
    }
    /** @return iterable<string, array{SignatureLockAction, array<string>, bool}> */
    public static function locks(): iterable
    {
        yield 'all' => [SignatureLockAction::All, [], false];
        yield 'include exact' => [SignatureLockAction::Include, ['group.approval'], false];
        yield 'include descendants' => [SignatureLockAction::Include, ['group'], false];
        yield 'include unrelated' => [SignatureLockAction::Include, ['unrelated'], true];
        yield 'exclude exact' => [SignatureLockAction::Exclude, ['group.approval'], true];
        yield 'exclude descendants' => [SignatureLockAction::Exclude, ['group'], true];
        yield 'exclude unrelated' => [SignatureLockAction::Exclude, ['unrelated'], false];
    }
    #[DataProvider('locks')]
    public function testActualHistoricalFieldMdpControlsSignatureValuesAndDescendants(SignatureLockAction $action, array $names, bool $allowed): void
    {
        $lock = new SignatureFieldLock($action, $action === SignatureLockAction::All ? null : new ArrayType(...array_map(static fn (string $name) => new TextString($name), $names)));
        if (!$allowed) {
            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('historical FieldMDP');
        }
        $second = $this->operation('approval');
        $prepared = $this->following($this->operation('author', $lock), $second, true);
        self::assertSame('group.approval', (new FrozenSignatureGraph($prepared->revision))->bindings($prepared->revision->binding($second->signature)->identifier)[0]->qualifiedName);
        self::assertNotEmpty($second->sign($prepared)->value->bytes);
    }
    public function testAnAdobeP3DeclarationCannotIncreaseHistoricalDocMdpP2(): void
    {
        $second = $this->operation('approval', new SignatureFieldLock(SignatureLockAction::Include, new ArrayType(), new IntegerType(3)));
        $prepared = $this->following($this->operation('author', certification: DocMdpPermissions::FormFillingTemplatesAndSigning), $second);
        $contract = new SignatureFieldPolicyContract($prepared, $second->backend, $second->intent);
        self::assertSame(2, $contract->permissions->integer());
        self::assertNotEmpty($second->sign($prepared)->value->bytes);
    }
    public function testAnAdobeP1DeclarationTightensHistoricalDocMdpP2ForFollowingChanges(): void
    {
        $second = $this->operation('approval', new SignatureFieldLock(SignatureLockAction::Include, new ArrayType(), new IntegerType(1)));
        $prepared = $this->following($this->operation('author', certification: DocMdpPermissions::FormFillingTemplatesAndSigning), $second);
        $contract = new SignatureFieldPolicyContract($prepared, $second->backend, $second->intent);
        self::assertSame(1, $contract->permissions->integer());
        self::assertNotEmpty($second->sign($prepared)->value->bytes);
    }
    public function testAFirstCertificationWithoutPermsIsValidForDetection(): void
    {
        $operation = $this->operation('author', certification: DocMdpPermissions::NoChanges);
        $catalog = new DocumentCatalog(new PageTreeNode(), $this->entry('AcroForm', new IndirectReference(new AcroForm((new FieldTree())->addRoot($operation->field)))));
        $prepared = (new SignatureWriter())->prepare(new FileStructure(new FileHeader(), new FileBody($catalog)), $operation->signature);
        self::assertNotEmpty($operation->sign($prepared)->value->bytes);
        self::assertFalse((new FrozenSignatureGraph($prepared->revision))->catalog->has('Perms'));
    }
    public function testActualPermissionsCannotMisidentifyAnApprovalAsTheCertification(): void
    {
        $author = $this->operation('author', certification: DocMdpPermissions::FormFillingTemplatesAndSigning);
        $approval = $this->operation('approval');
        $fields = (new FieldTree())->addRoot($author->field)->addRoot($approval->field);
        $catalog = new DocumentCatalog(new PageTreeNode(), $this->entry('AcroForm', new IndirectReference(new AcroForm($fields))), $this->entry('Perms', new DictionaryType($this->entry('DocMDP', new IndirectReference($approval->signature)))));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('matching signature transform');
        (new SignatureWriter())->prepare(new FileStructure(new FileHeader(), new FileBody($catalog)), $author->signature);
    }
    public function testCopiedFieldMdpNamesCannotDifferFromTheActualSignedFieldLock(): void
    {
        $data = new SignatureDataReference();
        $transform = new FieldMdpTransform(new SignatureFieldLock(SignatureLockAction::Include, new ArrayType(new TextString('different'))));
        $pending = new PendingSignature(new SignatureCapacity(8192), SignatureFormat::Pkcs7Detached, entries: $this->entry('Reference', new SignatureReferences(new SignatureReference($transform, $data))));
        $field = new SignatureField(new PartialFieldName(new TextString('approval')), value: new IndirectReference($pending), lock: new SignatureFieldLock(SignatureLockAction::Include, new ArrayType(new TextString('actual'))));
        $catalog = new DocumentCatalog(new PageTreeNode(), $this->entry('AcroForm', new IndirectReference(new AcroForm((new FieldTree())->addRoot($field)))));
        $data->bind(new IndirectReference($catalog));
        $prepared = (new SignatureWriter())->prepare(new FileStructure(new FileHeader(), new FileBody($catalog)), $pending);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('preserves every actual lock field name');
        $prepared->signWithPolicy($this->backend());
    }
    public function testChangingCurrentFormMetadataCannotEraseHistoricalFieldRestrictions(): void
    {
        $first = $this->operation('author', new SignatureFieldLock(SignatureLockAction::Include, new ArrayType(new TextString('approval'))));
        $tree = (new FieldTree())->addRoot($first->field);
        $form = new AcroForm($tree);
        $catalog = new DocumentCatalog(new PageTreeNode(), $this->entry('AcroForm', new IndirectReference($form)));
        $first->bindCatalog(new IndirectReference($catalog));
        $signed = $first->sign((new SignatureWriter())->prepare(new FileStructure(new FileHeader(), new FileBody($catalog)), $first->signature));
        $second = $this->operation('approval');
        $replacementForm = new AcroForm((new FieldTree())->addRoot($second->field));
        $replacement = new IndirectObject($replacementForm, $signed->revision->binding($form)->identifier);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('historical FieldMDP');
        $revision = (new IncrementalUpdate($signed->revision, new FileBody($replacement)))->toRevision();
        (new SignatureWriter())->prepareRevision($revision, $second->signature);
    }
    public function testFinalizationKeepsExactlyTheNestedDictionaryThatWasActuallyEmitted(): void
    {
        $build = new MutableSignatureBuildFixture();
        $pending = new PendingSignature(new SignatureCapacity(8192), SignatureFormat::Pkcs7Detached, entries: $this->entry('Prop_Build', $build));
        $field = new SignatureField(new PartialFieldName(new TextString('approval')), value: new IndirectReference($pending));
        $catalog = new DocumentCatalog(new PageTreeNode(), $this->entry('AcroForm', new IndirectReference(new AcroForm((new FieldTree())->addRoot($field)))));
        $prepared = (new SignatureWriter())->prepare(new FileStructure(new FileHeader(), new FileBody($catalog)), $pending);
        $build->changed = true;
        $signed = $prepared->signWithPolicy($this->backend());
        self::assertStringContainsString('/BuildState (original)', $signed->revision->bytes);
        self::assertSame('original', $signed->revision->binding($pending)->dictionary->get('Prop_Build')->get('BuildState')->value);
    }

    private function usageReservation(): PendingSignature
    {
        return new PendingSignature(new SignatureCapacity(8192), SignatureFormat::Pkcs7Detached, entries: $this->entry('Reference', new SignatureReferences(new SignatureReference(new UsageRightsTransform(UsageRightsMode::ByteRange)))));
    }

    public function testNativeUpdatesCannotAssumeAUsageRightsGrantAuthority(): void
    {
        $first = $this->usageReservation();
        $catalog = new DocumentCatalog(new PageTreeNode(), $this->entry('Perms', new DictionaryType($this->entry('UR3', new IndirectReference($first)))));
        // Mathematical signing does not assert that this public test identity is a granting authority.
        $signed = (new SignatureWriter())->prepare(new FileStructure(new FileHeader(), new FileBody($catalog)), $first)->sign($this->backend());
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('authorized granting identity');
        for ($index = 2; $index <= 3; ++$index) {
            $pending = $this->usageReservation();
            $replacement = new DocumentCatalog((new FrozenSignatureGraph($signed->revision))->catalog->get('Pages'), $this->entry('Perms', new DictionaryType($this->entry('UR3', new IndirectReference($pending)))));
            $object = new IndirectObject($replacement, $signed->revision->binding($signed->revision->root->target())->identifier);
            $revision = (new IncrementalUpdate($signed->revision, new FileBody($object)))->toRevision();
            if ($index === 3) {
                $this->expectException(\LogicException::class);
                $this->expectExceptionMessage('at most two usage-rights signatures');
            }
            $prepared = (new SignatureWriter())->prepareRevision($revision, $pending);
            $signed = $prepared->sign($this->backend());
            self::assertNotEmpty($signed->value->bytes);
        }
    }

    public function testAUsageRightsSignatureCannotBeAttachedToAnApprovalField(): void
    {
        $pending = $this->usageReservation();
        $field = new SignatureField(new PartialFieldName(new TextString('approval')), value: new IndirectReference($pending));
        $catalog = new DocumentCatalog(new PageTreeNode(), $this->entry('Perms', new DictionaryType($this->entry('UR3', new IndirectReference($pending)))), $this->entry('AcroForm', new IndirectReference(new AcroForm((new FieldTree())->addRoot($field)))));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not to a form field');
        (new SignatureWriter())->prepare(new FileStructure(new FileHeader(), new FileBody($catalog)), $pending);
    }

    /** @return iterable<string, array{bool}> */
    public static function retiredValues(): iterable
    {
        yield 'approval remains possible' => [false];
        yield 'certification remains subsequent' => [true];
    }

    #[DataProvider('retiredValues')]
    public function testObsoleteValueResolvesToNullWithoutErasingTheActualSignedHistory(bool $certification): void
    {
        $first = $this->operation('first');
        $next = $this->operation('next', certification: $certification ? DocMdpPermissions::FormFillingTemplatesAndSigning : null);
        $fields = (new FieldTree())->addRoot($first->field)->addRoot($next->field);
        $catalog = new DocumentCatalog(new PageTreeNode(), $this->entry('AcroForm', new IndirectReference(new AcroForm($fields))));
        $signed = $first->sign((new SignatureWriter())->prepare(new FileStructure(new FileHeader(), new FileBody($catalog)), $first->signature));
        $identifier = $signed->revision->binding($first->signature)->identifier;
        $revision = (new IncrementalUpdate($signed->revision, new FileBody($next->signature), null, null, new ReleaseIndirectObject($identifier)))->toRevision();
        $graph = new FrozenSignatureGraph($revision);
        self::assertNull($graph->resolve($graph->fields()[0]->effective('V')));
        self::assertSame([], $graph->bindings($identifier));
        if ($certification) {
            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('first signed field');
        }
        $prepared = (new SignatureWriter())->prepareRevision($revision, $next->signature);
        self::assertNotEmpty($next->sign($prepared)->value->bytes);
        self::assertSame($signed->revision, $prepared->revision->previous);
    }
}

final class MutableSignatureBuildFixture extends DictionaryType
{
    public bool $changed = false;
    public function entries(): iterable
    {
        yield new Entry(new NameType('BuildState'), new TextString($this->changed ? 'changed' : 'original'));
    }
}
