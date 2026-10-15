<?php

declare(strict_types=1);

namespace PDF\Tests\Support;

use PDF\Document\Forms as Form;
use PDF\Document\Signatures as Sig;
use PDF\Document\Signatures\Policies as Policy;
use PDF\Security as Sec;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\IndirectObject;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\FileStructure as File;

/** Actual encrypted UR3 source with a public test signing credential. */
final readonly class EncryptedUsageRightsFixture
{
    public File\FileRevision $revision;
    public Form\TextField $field;
    public Sig\UsageRightsAuthority $authority;

    public function __construct(Sec\FileEncryption $encryption, string $version = '2.0', File\CrossReferenceFormat $format = File\CrossReferenceFormat::Table, bool $addField = false)
    {
        $backend = (new ProtectedRevisionFixture())->backend;
        $pending = new Sig\PendingSignature(
            new Sig\SignatureCapacity(8192),
            Sig\SignatureFormat::Pkcs7Detached,
            entries: new Entry(new NameType('Reference'), new Policy\SignatureReferences(new Policy\SignatureReference(new Policy\UsageRightsTransform(
                Policy\UsageRightsMode::ByteRange,
                true,
                null,
                Policy\UsageRight::FullSave,
                Policy\UsageRight::FillField,
                Policy\UsageRight::ModifySignature,
                Policy\UsageRight::CreateAnnotation,
                Policy\UsageRight::ModifyAnnotation,
                Policy\UsageRight::DeleteAnnotation,
                Policy\UsageRight::SpawnTemplate,
                ...($addField ? [Policy\UsageRight::AddField] : []),
            ))))
        );
        $this->field = new Form\TextField(new Form\PartialFieldName(new TextString('value')), value: new TextString('original'));
        $font = require dirname(__DIR__, 2) . '/examples/fixtures/pdf20-courier-font.php';
        $resources = new DictionaryType(new Entry(new NameType('Font'), new DictionaryType(new Entry(new NameType('F'), new IndirectReference($font)))));
        $appearance = new Form\DefaultAppearance(new \PDF\Syntax\ContentStreamsResources\Operators\SetTextFontAndSize(new NameType('F'), new IntegerType(12)));
        $form = new Form\AcroForm((new Form\FieldTree($appearance, $resources))->addRoot($this->field));
        $page = new \PDF\Syntax\DocumentStructure\PageTree\PageObject(new \PDF\Syntax\DataStructure\Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(200), new IntegerType(200)), new \PDF\Document\ResourceDictionary());
        $extensions = $version === '2.0'
            ? new DictionaryType(new Entry(new NameType('ISO_'), new ArrayType(\PDF\Document\Extensions\IsoTechnicalSpecification::AesGcm->dictionary())))
            : new DictionaryType(new Entry(new NameType('ADBE'), new DictionaryType(new Entry(new NameType('BaseVersion'), new NameType('1.7')), new Entry(new NameType('ExtensionLevel'), new IntegerType(3)))));
        $catalog = new \PDF\Syntax\DocumentStructure\DocumentCatalog(
            (new \PDF\Syntax\DocumentStructure\PageTree\PageTreeNode())->addKid($page),
            new Entry(new NameType('AcroForm'), new IndirectReference($form)),
            new Entry(new NameType('Perms'), new DictionaryType(new Entry(new NameType('UR3'), new IndirectReference($pending)))),
            new Entry(new NameType('Extensions'), $extensions)
        );
        $id = $encryption instanceof Sec\HistoricalStandardEncryption ? $encryption->identifier->value : new HexadecimalString(str_repeat('u', 16));
        $file = new \PDF\FileStructure(new File\FileHeader($version), new File\FileBody($catalog), trailerEntries: new DictionaryType(new Entry(new NameType('ID'), new ArrayType($id, $id))), crossReferenceFormat: $format);
        $prepared = (new \PDF\Serialization\SignatureWriter(new \PDF\Serialization\FileWriter(encryption: $encryption)))->prepare($file, $pending);
        $this->revision = $prepared->sign($backend)->revision;
        $fingerprint = hash('sha256', $this->revision->verifiedSignature->facts->certificate->certificate()->value);
        $this->authority = new class ($fingerprint) implements Sig\UsageRightsAuthority {
            public function __construct(private string $fingerprint)
            {
            }
            public function permits(Sig\VerifiedRevisionSignature $signature): bool
            {
                return hash_equals($this->fingerprint, hash('sha256', $signature->facts->certificate->certificate()->value));
            }
        };
    }

    public function fill(Sig\PasswordUsageRightsAuthentication|Sig\CertificateUsageRightsAuthentication|null $authentication, ?File\FileRevision $source = null): File\FileRevision
    {
        $source ??= $this->revision;
        $field = $source->binding($this->field);
        $authorization = new Sig\UsageRightsAuthorization($this->authority, Policy\UsageRight::FillField, ...($authentication === null ? [] : [$authentication]));
        return (new File\IncrementalUpdate($source, new File\FileBody(new IndirectObject($field->dictionary->with(new Entry(new NameType('V'), new TextString('changed'))), $field->identifier)), null, null, $authorization))->toRevision();
    }
}
