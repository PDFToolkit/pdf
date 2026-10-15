<?php

declare(strict_types=1);

namespace PDF\Tests\Support;

use PDF\Document\Forms\AcroForm;
use PDF\Document\Forms\FieldTree;
use PDF\Document\Forms\PartialFieldName;
use PDF\Document\Forms\SignatureField;
use PDF\Document\Forms\SignatureFieldLock;
use PDF\Document\Forms\TextField;
use PDF\Document\ResourceDictionary;
use PDF\Document\Signatures\DocumentSecurityStore;
use PDF\Document\Signatures\PendingDocumentTimestamp;
use PDF\Document\Signatures\PendingSignature;
use PDF\Document\Signatures\PendingSignatureDictionary;
use PDF\Document\Signatures\PreparedSignature;
use PDF\Document\Signatures\SignatureCapacity;
use PDF\Document\Signatures\SignatureFieldSigning;
use PDF\Document\Signatures\SignatureFormat;
use PDF\Document\Signatures\SignatureSigningIntent;
use PDF\Document\Signatures\SignatureValue;
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
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\FileStructure\CrossReferenceFormat;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\FileStructure\FileRevision;
use PDF\Syntax\FileStructure\IncrementalUpdate;
use PDF\Syntax\FileStructure\ReleaseIndirectObject;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringObject\HexadecimalString;

/** Public test credential and real native policy signatures, also used by the independent oracle generator. */
final readonly class ProtectedRevisionFixture
{
    public OpenSslSignatureBackend $backend;
    public SignatureFieldSigning $author;
    public SignatureField $approval;
    public TextField $text;
    public StreamObject $content;
    public PageObject $page;
    public DocumentCatalog $catalog;
    public FileRevision $revision;
    public PreparedSignature $prepared;

    public function __construct(?DocMdpPermissions $permission = DocMdpPermissions::NoChanges, ?SignatureFieldLock $lock = null, CrossReferenceFormat $format = CrossReferenceFormat::Table, string $version = '2.0', bool $opaque = false)
    {
        $signing = dirname(__DIR__) . '/Fixtures/signing/';
        $chain = new CertificateChain(new X509Certificate(new HexadecimalString(file_get_contents($signing . 'signer.der'))));
        $this->backend = new OpenSslSignatureBackend(new OpenSslIdentity($chain, file_get_contents($signing . 'signer-private-key.pem')));
        $this->author = new SignatureFieldSigning($this->backend, new PartialFieldName(new TextString('author')), new SignatureCapacity(8192), new SignatureSigningIntent(certification: $permission), lock: $lock);
        $this->approval = new SignatureField(new PartialFieldName(new TextString('approval')));
        $this->text = new TextField(new PartialFieldName(new TextString('name')), value: new TextString('original'));
        $font = require dirname(__DIR__, 2) . '/examples/fixtures/pdf20-courier-font.php';
        $resources = new DictionaryType(new Entry(new NameType('Font'), new DictionaryType(new Entry(new NameType('F'), new IndirectReference($font)))));
        $appearance = new \PDF\Document\Forms\DefaultAppearance(new \PDF\Syntax\ContentStreamsResources\Operators\SetTextFontAndSize(new NameType('F'), new IntegerType(12)));
        $form = new AcroForm((new FieldTree($appearance, $resources))->addRoot($this->author->field)->addRoot($this->approval)->addRoot($this->text));
        $this->content = new StreamObject("q\nQ");
        $this->page = new PageObject(new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(200), new IntegerType(200)), new ResourceDictionary(), $this->content);
        $entries = [new Entry(new NameType('AcroForm'), new IndirectReference($form)), new Entry(new NameType('Extensions'), new DictionaryType(new Entry(new NameType('ADBE'), new DictionaryType(new Entry(new NameType('BaseVersion'), new NameType('1.7')), new Entry(new NameType('ExtensionLevel'), new IntegerType(3))))))];
        if ($permission !== null) {
            $entries[] = new Entry(new NameType('Perms'), $this->author->certificationPermissions());
        }
        $this->catalog = new DocumentCatalog((new PageTreeNode())->addKid($this->page), ...$entries);
        $this->author->bindCatalog(new IndirectReference($this->catalog));
        $this->prepared = (new SignatureWriter())->prepare(new FileStructure(new FileHeader($version), new FileBody($this->catalog), crossReferenceFormat: $format), $this->author->signature);
        $this->revision = ($opaque ? $this->prepared->finalize(new SignatureValue('opaque, not a valid CMS')) : $this->author->sign($this->prepared))->revision;
    }

    public function mutation(string $attack): IncrementalUpdate
    {
        $catalog = $this->revision->binding($this->catalog);
        $value = $catalog->dictionary;
        $objects = [];
        $operations = [];
        $trailer = null;
        $root = null;
        if ($attack === 'lang') {
            $value = $value->with($this->entry('Lang', new TextString('fr-FR')));
        } elseif ($attack === 'remove-perms') {
            $value = $value->without('Perms');
        } elseif ($attack === 'remove-form') {
            $value = $value->without('AcroForm');
        } elseif ($attack === 'root') {
            $replacement = new DictionaryType(...iterator_to_array($value->without('Perms')->without('AcroForm')->entries(), false));
            $objects[] = $replacement;
            $root = new IndirectReference($replacement);
        } elseif ($attack === 'trailer') {
            $trailer = new DictionaryType($this->entry('Info', new IndirectReference(new DictionaryType($this->entry('Title', new TextString('changed'))))));
        } elseif ($attack === 'release') {
            $operations[] = new ReleaseIndirectObject($this->revision->binding($this->content)->identifier);
        } elseif ($attack === 'field-value' || $attack === 'field-name') {
            $field = $this->revision->binding($this->text);
            $objects[] = new IndirectObject($field->dictionary->with($this->entry($attack === 'field-value' ? 'V' : 'T', new TextString('changed'))), $field->identifier);
        } elseif ($attack === 'content') {
            $objects[] = new IndirectObject(new StreamObject('0 0 20 20 re f'), $this->revision->binding($this->content)->identifier);
        } elseif ($attack === 'page') {
            $page = $this->revision->binding($this->page);
            $objects[] = new IndirectObject($page->dictionary->with($this->entry('Rotate', new IntegerType(90))), $page->identifier);
        } elseif ($attack === 'fake-xref') {
            $objects[] = new StreamObject('arbitrary hidden bytes', new DictionaryType($this->entry('Type', new NameType('XRef'))));
        } elseif ($attack === 'orphan') {
            $objects[] = new StreamObject('arbitrary orphan bytes');
        } else {
            throw new \InvalidArgumentException('Unknown fixture attack.');
        }
        $objects[] = new IndirectObject($value, $catalog->identifier);
        return new IncrementalUpdate($this->revision, new FileBody(...$objects), $root, $trailer, ...$operations);
    }

    /** @return array{IncrementalUpdate,PendingSignatureDictionary} */
    public function signing(bool $timestamp = false, string $attack = ''): array
    {
        $pending = $timestamp ? new PendingDocumentTimestamp(new SignatureCapacity(8192)) : new PendingSignature(new SignatureCapacity(8192), SignatureFormat::Pkcs7Detached);
        $record = $this->revision->binding($this->approval);
        $field = $record->dictionary->with($this->entry('V', new IndirectReference($pending)));
        $objects = [new IndirectObject($field, $record->identifier)];
        if ($attack !== '') {
            array_push($objects, ...$this->mutation($attack)->body->objects());
        }
        return [new IncrementalUpdate($this->revision, new FileBody(...$objects)), $pending];
    }

    public function dss(): IncrementalUpdate
    {
        $catalog = $this->revision->binding($this->catalog);
        return new IncrementalUpdate($this->revision, new FileBody(new IndirectObject($catalog->dictionary->with($this->entry('DSS', new IndirectReference(new DocumentSecurityStore()))), $catalog->identifier)));
    }

    private function entry(string $name, BasicType $value): Entry
    {
        return new Entry(new NameType($name), $value);
    }
}
