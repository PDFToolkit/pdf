<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

use PDF\Document\Forms\SignatureFieldLock;
use PDF\Document\Signatures\Policies\DocMdpPermissions;
use PDF\Document\Signatures\Policies\FrozenSignatureSeedReader;
use PDF\Document\Signatures\Policies\SignatureCertificateUrlUsage;
use PDF\Document\Signatures\Policies\SignatureDocumentLock;
use PDF\Document\Signatures\Policies\SignatureSeedValue;
use PDF\Document\Signatures\Policies\SignatureSeedFlag;
use PDF\Document\Signatures\Policies\SignatureSigningChoice;
use PDF\Document\Signatures\Policies\SignatureSigningEvidence;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\DataStructure\TextStringDecoder;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StringType;
use PDF\Security\CertificateChain;

/** Re-evaluate all obligations against emitted field/signature/catalog metadata before any finalization. */
final readonly class SignatureFieldPolicyContract
{
    public FrozenSignatureGraph $graph;
    public FrozenSignatureField $field;
    public ?SignatureSeedValue $seed;
    public ?SignatureFieldLock $lock;
    public SignatureSigningChoice $choice;
    public IntegerType $permissions;
    private EffectivePdfVersion $version;
    private CertificateChain $certificate;

    public function __construct(private PreparedSignature $prepared, private PolicySignatureBackend $backend, SignatureSigningIntent $intent = new SignatureSigningIntent(), private ?SignatureCertificateUrlUsage $urlUsage = null)
    {
        $this->graph = new FrozenSignatureGraph($prepared->revision);
        $identifier = $prepared->revision->binding($prepared->signature)->identifier;
        (new SignatureDocumentSigningRules())->validate($this->graph, $identifier);
        $fields = $this->graph->bindings($identifier);
        if (count($fields) !== 1) {
            throw new \LogicException('Field policy signing requires one actual field binding; usage-rights authorities use a separate contract.');
        }
        $this->field = $fields[0];
        $dictionary = $prepared->revision->binding($identifier)->dictionary ?? throw new \InvalidArgumentException('The prepared signature has its actual dictionary.');
        $reader = new FrozenSignatureSeedReader($this->graph);
        $seed = $this->graph->resolve($this->field->dictionary->get('SV'));
        if ($seed !== null && !$seed instanceof DictionaryType) {
            throw new \InvalidArgumentException('The actual field SV identifies its seed dictionary.');
        }
        $this->seed = $seed === null ? null : $reader->seed($seed);
        $lock = $this->graph->resolve($this->field->dictionary->get('Lock'));
        if ($lock !== null && !$lock instanceof DictionaryType) {
            throw new \InvalidArgumentException('The actual field Lock identifies its lock dictionary.');
        }
        $this->lock = $lock === null ? null : $reader->lock($lock);
        $methods = (new SignatureDocumentSigningRules())->methods($this->graph, $dictionary);
        if ($this->lock !== null) {
            $this->validateLockCopy($this->lock, $methods['FieldMDP'] ?? null);
        }
        $format = $dictionary->get('SubFilter');
        $handler = $dictionary->get('Filter');
        if (!$format instanceof NameType || SignatureFormat::tryFrom($format->value) !== $prepared->signature->format) {
            throw new \InvalidArgumentException('The actual emitted SubFilter matches the prepared signature format.');
        }
        $capabilities = $backend->capabilities();
        if (!$handler instanceof NameType || $handler->value !== $capabilities->handler->value) {
            throw new \InvalidArgumentException('The emitted signature Filter matches the selected handler identity.');
        }
        $this->certificate = $backend->certificateChain();
        $facts = $backend->certificateFacts();
        if (!hash_equals($this->certificate->signer->value->value, $facts->certificate()->value)) {
            throw new \InvalidArgumentException('The selected handler facts belong to its actual signing credential.');
        }
        if ($prepared->signature->format === SignatureFormat::X509RsaSha1) {
            $this->validateCertificate($dictionary);
        }
        $digest = (new SignatureDigestSelection())->select($this->seed, $facts, $backend->supportedDigests($prepared->signature->format), $intent->digest);
        $reason = $this->text($dictionary->get('Reason'));
        if ($intent->reason !== null && $intent->reason->text !== $reason?->text) {
            throw new \InvalidArgumentException('The requested Reason is the Reason actually emitted in the signature dictionary.');
        }
        $certification = null;
        if (isset($methods['DocMDP'])) {
            $permissions = $methods['DocMDP']->get('P') ?? new IntegerType(2);
            if (!$permissions instanceof NumericType || !in_array($permissions->value, [1, 2, 3, 1.0, 2.0, 3.0], true)) {
                throw new \InvalidArgumentException('The actual DocMDP permission is1,2 or3.');
            }
            $certification = DocMdpPermissions::from((int) $permissions->value);
        }
        if ($intent->certification !== null && $intent->certification !== $certification) {
            throw new \InvalidArgumentException('The requested certification equals the actual DocMDP transform.');
        }
        $history = new SignaturePermissionHistory($this->graph);
        $history->assertSigning($this->field);
        $this->permissions = $history->after($this->lock, $certification === null ? null : new IntegerType($certification->value));
        $legal = $this->graph->resolve($this->graph->catalog->get('Legal'));
        $attestation = $legal instanceof DictionaryType ? $this->text($this->graph->resolve($legal->get('Attestation'))) : null;
        if ($intent->legalAttestation !== null && $intent->legalAttestation->text !== $attestation?->text) {
            throw new \InvalidArgumentException('The chosen legal attestation is emitted in the actual catalog Legal dictionary.');
        }
        $documentLock = $this->permissions->integer() === 1 ? SignatureDocumentLock::Lock : SignatureDocumentLock::DoNotLock;
        if ($intent->documentLock !== null && $intent->documentLock !== SignatureDocumentLock::Automatic && $intent->documentLock !== $documentLock) {
            throw new \InvalidArgumentException('The document-lock choice is supported by the actual signature permission declarations.');
        }
        $intent->appearance?->validate($this->graph, $this->field);
        if (($intent->includeTimeStamp || $this->seed?->timeStamp?->required) && !$backend->supportsEvidence(SignatureEvidenceCapability::TimeStamp)) {
            throw new \LogicException('The selected native handler cannot embed an RFC3161 timestamp; select an explicit external signature/evidence provider.');
        }
        if (($intent->includeRevocation || ($this->seed !== null && $this->seed->addRevocation && $this->seed->flags->requires(SignatureSeedFlag::Revocation))) && !$backend->supportsEvidence(SignatureEvidenceCapability::Revocation)) {
            throw new \LogicException('The selected native handler cannot embed verified revocation signed attributes; select an explicit external signature/evidence provider.');
        }
        $this->choice = new SignatureSigningChoice($capabilities, $prepared->signature->format, $digest, $reason, $certification, $attestation, $documentLock, $intent->appearance?->name, $intent->includeRevocation, $intent->includeTimeStamp);
        $this->seed?->validateChoice($this->choice, $facts, $urlUsage);
        $this->version = new EffectivePdfVersion($prepared->revision->header, $this->graph->catalog);
        $this->seed?->validateVersion($this->version);
        $this->seed?->validateCatalog($this->graph->catalog);
        if ($this->lock?->permissions !== null) {
            $this->validateAdobeDeclaration();
        }
        $backend->validateVersion($this->version, $prepared->signature->format);
    }

    public function validateOutput(SignatureValue $value, SignatureOutputVerifier $verifier, SignatureSigningEvidence $evidence): VerifiedSignatureFacts
    {
        if (strlen($value->bytes) > $this->prepared->signature->capacity->bytes) {
            throw new \LengthException('Signature output exceeds its reserved capacity; prepare a new reservation and sign again.');
        }
        $input = new SignatureVerificationInput($this->prepared->coveredBytes(), $value, $this->choice->format, $this->certificate);
        $actual = $verifier->verify($input);
        $actual->validateBinding($input);
        if ($actual->algorithms->digest !== $this->choice->digest) {
            throw new \InvalidArgumentException('The verified actual digest differs from the negotiated supported signing choice.');
        }
        $actual->algorithms->validateVersion($this->version, $this->choice->format);
        $this->seed?->validateChoice($this->choice, $actual->certificate, $this->urlUsage);
        if ($this->seed !== null) {
            $this->seed->validateEvidence($this->choice, $value, $actual->certificate, $evidence);
        } else {
            if ($this->choice->includeTimeStamp) {
                $evidence->assertTimeStamp(null, $value);
            }
            if ($this->choice->includeRevocation) {
                $evidence->assertRevocation($actual->certificate, $value);
            }
        }
        return $actual;
    }

    private function validateLockCopy(SignatureFieldLock $lock, ?DictionaryType $parameters): void
    {
        $action = $parameters?->get('Action');
        if (!$action instanceof NameType || $action->value !== $lock->action->value) {
            throw new \InvalidArgumentException('FieldMDP copies the Action of the actual signed field lock.');
        }
        $expected = $lock->get('Fields');
        $actual = $parameters?->get('Fields');
        if ($expected === null ? $actual !== null : (!$expected instanceof ArrayType || !$actual instanceof ArrayType || count($expected->items) !== count($actual->items))) {
            throw new \InvalidArgumentException('FieldMDP copies the Fields array of the actual signed field lock.');
        }
        if ($expected instanceof ArrayType && $actual instanceof ArrayType) {
            foreach ($expected->items as $index => $name) {
                if ($this->text($name)?->text !== $this->text($actual->items[$index])?->text) {
                    throw new \InvalidArgumentException('FieldMDP preserves every actual lock field name in order.');
                }
            }
        }
    }

    private function validateCertificate(DictionaryType $signature): void
    {
        $value = $signature->get('Cert');
        $items = $value instanceof ArrayType ? $value->items : [$value];
        $expected = $this->certificate->object()->items;
        if (count($items) !== count($expected)) {
            throw new \InvalidArgumentException('PKCS#1 Cert contains the actual signing certificate chain.');
        }
        foreach ($items as $index => $certificate) {
            $target = $expected[$index];
            if (!$certificate instanceof StringType || !$target instanceof StringType || !hash_equals($target->value, $certificate->value)) {
                throw new \InvalidArgumentException('PKCS#1 Cert agrees with every actual credential chain entry.');
            }
        }
    }

    private function text(mixed $value): ?TextString
    {
        if ($value === null) {
            return null;
        }
        if (!$value instanceof StringType) {
            throw new \InvalidArgumentException('Actual signature text metadata is a direct PDF text string.');
        }
        return new TextString((new TextStringDecoder())->decode($value));
    }

    private function validateAdobeDeclaration(): void
    {
        $this->version->require(PdfVersion::PDF17, 'Adobe signature lock permission');
        $extensions = $this->graph->catalog->get('Extensions');
        $adobe = $extensions instanceof DictionaryType ? $extensions->get('ADBE') : null;
        $base = $adobe instanceof DictionaryType ? $adobe->get('BaseVersion') : null;
        $level = $adobe instanceof DictionaryType ? $adobe->get('ExtensionLevel') : null;
        if (!$base instanceof NameType || $base->value !== '1.7' || !$level instanceof IntegerType || $level->integer() < 3) {
            throw new \InvalidArgumentException('Adobe signature lock P requires the actual direct ADBE BaseVersion1.7/ExtensionLevel3 declaration.');
        }
    }
}
