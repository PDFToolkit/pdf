<?php

declare(strict_types=1);

namespace PDF\Tests\Support;

use PDF\Document\Forms as Form;
use PDF\Document\Signatures as Sig;
use PDF\Document\Signatures\Policies as Policy;
use PDF\Security as Security;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PDF\Syntax\FileStructure as File;

/** Public synthetic EdDSA credentials, actual PDF bytes and frozen policy verification. */
final readonly class SignatureSeedEc3Fixture
{
    public Sig\SignedRevision $signed;
    public Sig\PreparedSignature $prepared;
    public Sig\SignatureFieldSigning $operation;
    public string $certificate;

    public function __construct(string $name, File\CrossReferenceFormat $format = File\CrossReferenceFormat::Table, string $version = '2.0')
    {
        $fixtures = json_decode(file_get_contents(dirname(__DIR__) . '/Fixtures/iso32000-crypto/keys.json'), true, flags: JSON_THROW_ON_ERROR)['keys'];
        $fixture = array_values(array_filter($fixtures, static fn ($value) => $value['name'] === $name))[0];
        $this->certificate = $fixture['certificate'];
        $identity = new Security\OpenSslIdentity(new Security\CertificateChain(new Security\X509Certificate(new HexadecimalString(base64_decode($fixture['certificate'], true)))), $fixture['private_key']);
        $digest = $name === 'Ed25519' ? Policy\SignatureDigestMethod::SHA512 : Policy\SignatureDigestMethod::SHAKE256;
        $backend = new Security\ModernCmsSignatureBackend($identity, $digest, new Security\OpenSslEdDsaSignaturePrimitive(getenv('PDFTOOLKIT_OPENSSL') ?: '/usr/bin/openssl', $fixture['private_key']));
        $seed = new Policy\SignatureSeedValue(new Policy\SignatureSeedFlags(Policy\SignatureSeedFlag::DigestMethod, Policy\SignatureSeedFlag::ParserCapability), new Entry(new NameType('DigestMethod'), new ArrayType(new NameType('SHA256'))), new Entry(new NameType('V'), new IntegerType(3)), Policy\SignatureSeedSemantics::Pdf20);
        $this->operation = new Sig\SignatureFieldSigning($backend, new Form\PartialFieldName(new \PDF\Syntax\DataStructure\TextString('approval')), new Sig\SignatureCapacity(8192), seed: $seed);
        $form = new Form\AcroForm((new Form\FieldTree())->addRoot($this->operation->field));
        $pages = (new \PDF\Syntax\DocumentStructure\PageTree\PageTreeNode())->addKid(new \PDF\Syntax\DocumentStructure\PageTree\PageObject(new \PDF\Syntax\DataStructure\Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(200), new IntegerType(200)), new \PDF\Document\ResourceDictionary()));
        $catalog = new \PDF\Syntax\DocumentStructure\DocumentCatalog($pages, new Entry(new NameType('AcroForm'), new IndirectReference($form)), new Entry(new NameType('Extensions'), new \PDF\Document\Extensions\IsoExtensions(\PDF\Document\Extensions\IsoTechnicalSpecification::DigitalSignatures, \PDF\Document\Extensions\IsoTechnicalSpecification::HashAlgorithms)));
        $this->prepared = (new \PDF\Serialization\SignatureWriter())->prepare(new \PDF\FileStructure(new File\FileHeader($version), new File\FileBody($catalog), crossReferenceFormat: $format), $this->operation->signature);
        $this->signed = $this->operation->sign($this->prepared);
    }
}
