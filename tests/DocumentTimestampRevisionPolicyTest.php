<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\Forms\AcroForm;
use PDF\Document\Forms\FieldTree;
use PDF\Document\Forms\PartialFieldName;
use PDF\Document\Forms\SignatureField;
use PDF\Document\Forms\SignatureFieldLock;
use PDF\Document\Forms\SignatureLockAction;
use PDF\Document\ResourceDictionary;
use PDF\Document\Signatures\DocumentSecurityStore;
use PDF\Document\Signatures\PendingDocumentTimestamp;
use PDF\Document\Signatures\PendingSignature;
use PDF\Document\Signatures\PreparedSignature;
use PDF\Document\Signatures\SignatureCapacity;
use PDF\Document\Signatures\SignatureFieldSigning;
use PDF\Document\Signatures\SignatureSigningIntent;
use PDF\Document\Signatures\Policies\DocMdpPermissions;
use PDF\FileStructure;
use PDF\Security\CertificateChain;
use PDF\Security\OpenSslIdentity;
use PDF\Security\OpenSslSignatureBackend;
use PDF\Security\X509Certificate;
use PDF\Serialization\SignatureWriter;
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
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DocumentTimestampRevisionPolicyTest extends TestCase
{
    /** @return array{FileRevision,DocumentCatalog,PageObject,StreamObject} */
    private function certified(CrossReferenceFormat $format = CrossReferenceFormat::Table, ?SignatureFieldLock $lock = null): array
    {
        $chain = new CertificateChain(new X509Certificate(new HexadecimalString(file_get_contents(__DIR__ . '/Fixtures/signing/signer.der'))));
        $backend = new OpenSslSignatureBackend(new OpenSslIdentity($chain, file_get_contents(__DIR__ . '/Fixtures/signing/signer-private-key.pem')));
        $operation = new SignatureFieldSigning($backend, new PartialFieldName(new TextString('author')), new SignatureCapacity(8192), new SignatureSigningIntent(certification: DocMdpPermissions::NoChanges), lock: $lock);
        $form = new AcroForm((new FieldTree())->addRoot($operation->field));
        $stream = new StreamObject("q\nQ");
        $page = new PageObject(new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(200), new IntegerType(200)), new ResourceDictionary(), $stream);
        $catalog = new DocumentCatalog((new PageTreeNode())->addKid($page),
            $this->entry('AcroForm', new IndirectReference($form)),
            $this->entry('Perms', $operation->certificationPermissions()),
            $this->entry('Extensions', new DictionaryType($this->entry('ADBE', new DictionaryType($this->entry('BaseVersion', new NameType('1.7')), $this->entry('ExtensionLevel', new IntegerType(3)))))));
        $operation->bindCatalog(new IndirectReference($catalog));
        $signed = $operation->sign((new SignatureWriter())->prepare(new FileStructure(new FileHeader('2.0'), new FileBody($catalog), crossReferenceFormat: $format), $operation->signature));
        return [$signed->revision, $catalog, $page, $stream];
    }

    /** @return iterable<string,array{CrossReferenceFormat}> */
    public static function layouts(): iterable
    {
        foreach (CrossReferenceFormat::cases() as $format) {
            yield $format->name => [$format];
        }
    }

    #[DataProvider('layouts')]
    public function testOnlyTimestampMaintenanceIsAcceptedAfterDocMdpP1(CrossReferenceFormat $format): void
    {
        [$prior, $catalog] = $this->certified($format);
        [$revision, $pending] = $this->following($prior, $catalog);
        self::assertSame($pending, (new PreparedSignature($revision, $pending))->signature);
        self::assertStringStartsWith($prior->bytes, $revision->bytes);
    }

    /** @return iterable<string,array{string}> */
    public static function attacks(): iterable
    {
        foreach (['page', 'content', 'metadata', 'widget', 'ordinary-signature', 'dss-private-page', 'dss-content-stream', 'orphan-object'] as $attack) {
            yield $attack => [$attack];
        }
    }

    #[DataProvider('attacks')]
    public function testTimestampCannotHideOtherChangesFromDocMdpP1(string $attack): void
    {
        [$prior, $catalog, $page, $content] = $this->certified();
        [$revision, $pending] = $this->following($prior, $catalog, $attack, $page, $content);
        $this->expectException(\LogicException::class);
        new PreparedSignature($revision, $pending);
    }

    public function testTimestampCannotIgnoreHistoricalFieldMdp(): void
    {
        [$prior, $catalog] = $this->certified(lock: new SignatureFieldLock(SignatureLockAction::All));
        [$revision, $pending] = $this->following($prior, $catalog);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('historical FieldMDP');
        new PreparedSignature($revision, $pending);
    }

    public function testTimestampCannotIgnoreAdditionalAdobeP1FieldLock(): void
    {
        [$prior, $catalog] = $this->certified(lock: new SignatureFieldLock(SignatureLockAction::Include, new ArrayType(), new IntegerType(1)));
        [$revision, $pending] = $this->following($prior, $catalog);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('field lock P1 remains binding');
        new PreparedSignature($revision, $pending);
    }

    /** @return array{FileRevision,PendingDocumentTimestamp} */
    private function following(FileRevision $prior, DocumentCatalog $catalog, string $attack = '', ?PageObject $page = null, ?StreamObject $content = null): array
    {
        $pending = new PendingDocumentTimestamp(new SignatureCapacity(8192));
        $fieldEntries = $attack === 'widget' ? [$this->entry('Subtype', new NameType('Widget')), $this->entry('Rect', new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(10), new IntegerType(10)))] : [];
        $field = new DictionaryType($this->entry('FT', new NameType('Sig')), $this->entry('T', new TextString('timestamp')), $this->entry('V', new IndirectReference($pending)), ...$fieldEntries);
        $catalogRecord = $prior->binding($catalog);
        $oldFormReference = $catalogRecord->dictionary->get('AcroForm');
        $formRecord = $prior->binding($oldFormReference->target());
        $oldFields = $formRecord->dictionary->get('Fields');
        $form = $formRecord->dictionary->with($this->entry('Fields', new ArrayType(...[...$oldFields->items, new IndirectReference($field)])))->with($this->entry('SigFlags', new IntegerType(3)));
        $catalogValue = $catalogRecord->dictionary->with($this->entry('DSS', new IndirectReference(new DocumentSecurityStore())));
        $objects = [new IndirectObject($form, $formRecord->identifier)];
        if (in_array($attack, ['page', 'dss-private-page'], true)) {
            $record = $prior->binding($page);
            $objects[] = new IndirectObject($record->dictionary->with($this->entry('Rotate', new IntegerType(90))), $record->identifier);
            if ($attack === 'dss-private-page') {
                $catalogValue = $catalogValue->with($this->entry('DSS', new IndirectReference(new DictionaryType($this->entry('Private', $prior->reference($page))))));
            }
        } elseif (in_array($attack, ['content', 'dss-content-stream'], true)) {
            $record = $prior->binding($content);
            $objects[] = new IndirectObject(new StreamObject("q\n0 0 10 10 re f\nQ"), $record->identifier);
            if ($attack === 'dss-content-stream') {
                $catalogValue = $catalogValue->with($this->entry('DSS', new IndirectReference(new DictionaryType($this->entry('Certs', new ArrayType($prior->reference($content)))))));
            }
        } elseif ($attack === 'metadata') {
            $catalogValue = $catalogValue->with($this->entry('Lang', new TextString('fr-FR')));
        } elseif ($attack === 'ordinary-signature') {
            $extra = new PendingSignature(new SignatureCapacity(8192), \PDF\Document\Signatures\SignatureFormat::Pkcs7Detached);
            $extraField = new DictionaryType($this->entry('FT', new NameType('Sig')), $this->entry('T', new TextString('approval')), $this->entry('V', new IndirectReference($extra)));
            $form = $form->with($this->entry('Fields', new ArrayType(...[...$oldFields->items, new IndirectReference($field), new IndirectReference($extraField)])));
            $objects[0] = new IndirectObject($form, $formRecord->identifier);
        } elseif ($attack === 'orphan-object') {
            $objects[] = new DictionaryType($this->entry('Private', new TextString('unrelated')));
        }
        $objects[] = new IndirectObject($catalogValue, $catalogRecord->identifier);
        return [(new IncrementalUpdate($prior, new FileBody(...$objects)))->toRevision(), $pending];
    }

    private function entry(string $name, \PDF\Syntax\FileStructure\BasicType $value): Entry
    {
        return new Entry(new NameType($name), $value);
    }
}
