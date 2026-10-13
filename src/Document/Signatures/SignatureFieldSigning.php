<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

use PDF\Document\Forms\PartialFieldName;
use PDF\Document\Forms\SignatureField;
use PDF\Document\Forms\SignatureFieldLock;
use PDF\Document\Signatures\Policies\DocMdpPermissions;
use PDF\Document\Signatures\Policies\DocMdpTransform;
use PDF\Document\Signatures\Policies\FieldMdpTransform;
use PDF\Document\Signatures\Policies\SignatureDataReference;
use PDF\Document\Signatures\Policies\SignatureDocumentLock;
use PDF\Document\Signatures\Policies\SignatureReference;
use PDF\Document\Signatures\Policies\SignatureReferences;
use PDF\Document\Signatures\Policies\SignatureSeedValue;
use PDF\Document\Signatures\Policies\SignatureSeedFlag;
use PDF\Document\Signatures\Policies\SignatureSigningChoice;
use PDF\Document\Signatures\Policies\SignatureSigningEvidence;
use PDF\Document\Signatures\Policies\UnavailableSignatureEvidence;
use PDF\Document\Signatures\Policies\SignatureCertificateUrlUsage;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;

/** ISO12.8.2.4, PDF482: build the recipient's field, reservation and copied direct transform parameters. */
final readonly class SignatureFieldSigning
{
    public PendingSignature $signature;
    public SignatureField $field;
    public SignatureSigningChoice $choice;
    public SignatureReferences $references;
    private ?SignatureDataReference $data;

    public function __construct(
        public PolicySignatureBackend $backend,
        ?PartialFieldName $name,
        SignatureCapacity $capacity,
        public SignatureSigningIntent $intent = new SignatureSigningIntent(),
        ?SignatureSeedValue $seed = null,
        ?SignatureFieldLock $lock = null,
        ?SignatureFormat $format = null,
        private ?SignatureCertificateUrlUsage $urlUsage = null
    ) {
        $capabilities = $backend->capabilities();
        $selected = $seed !== null && $seed->has('SubFilter') ? $seed->selectFormat($capabilities) : ($format ?? $capabilities->formats[0]);
        if ($format !== null && $seed !== null && !$seed->flags->requires(SignatureSeedFlag::SubFilter) && $seed->preferredFormat($capabilities) === null) {
            $selected = $format;
        }
        if ($format !== null && $selected !== $format) {
            throw new \InvalidArgumentException('The chosen format is the first supported seed SubFilter.');
        }
        $facts = $backend->certificateFacts();
        $digest = (new SignatureDigestSelection())->select($seed, $facts, $backend->supportedDigests($selected), $intent->digest);
        $documentLock = $intent->certification === DocMdpPermissions::NoChanges || $lock?->permissions?->integer() === 1 ? SignatureDocumentLock::Lock : SignatureDocumentLock::DoNotLock;
        if ($intent->documentLock !== null && $intent->documentLock !== SignatureDocumentLock::Automatic && $intent->documentLock !== $documentLock) {
            throw new \InvalidArgumentException('A requested document lock has an explicit DocMDP/P1 or Adobe field lock/P1 declaration.');
        }
        if (($intent->includeTimeStamp || $seed?->timeStamp?->required) && !$backend->supportsEvidence(SignatureEvidenceCapability::TimeStamp)) {
            throw new \LogicException('The native backend has no RFC3161 timestamp provider; explicitly select an external signature/evidence provider.');
        }
        if (($intent->includeRevocation || ($seed !== null && $seed->addRevocation && $seed->flags->requires(SignatureSeedFlag::Revocation))) && !$backend->supportsEvidence(SignatureEvidenceCapability::Revocation)) {
            throw new \LogicException('The native backend has no CMS revocation-evidence provider; explicitly select an external signature/evidence provider.');
        }
        $this->choice = new SignatureSigningChoice($capabilities, $selected, $digest, $intent->reason, $intent->certification, $intent->legalAttestation, $documentLock, $intent->appearance?->name, $intent->includeRevocation, $intent->includeTimeStamp);
        $seed?->validateChoice($this->choice, $facts, $urlUsage);
        $references = [];
        if ($intent->certification !== null) {
            $references[] = new SignatureReference(new DocMdpTransform($intent->certification));
        }
        $this->data = $lock === null ? null : new SignatureDataReference();
        if ($lock !== null) {
            $references[] = new SignatureReference(new FieldMdpTransform($lock), $this->data);
        }
        $this->references = new SignatureReferences(...$references);
        $entries = [];
        if ($references !== []) {
            $entries[] = new Entry(new NameType('Reference'), $this->references);
            $entries[] = new Entry(new NameType('V'), new IntegerType(1));
        }
        if ($intent->reason !== null) {
            $entries[] = new Entry(new NameType('Reason'), $intent->reason);
        }
        if ($selected === SignatureFormat::X509RsaSha1) {
            $entries[] = new Entry(new NameType('Cert'), $backend->certificateChain()->object());
        }
        $this->signature = new PendingSignature($capacity, $selected, $capabilities->handler, ...$entries);
        $this->field = new SignatureField($name, lock: $lock, value: new IndirectReference($this->signature), seedValue: $seed === null ? null : new IndirectReference($seed));
    }

    public function bindCatalog(IndirectReference $catalog): self
    {
        $this->data?->bind($catalog);
        return $this;
    }

    /** Explicit catalog contribution; combine additional handlers in the caller's permissions dictionary. */
    public function certificationPermissions(): ?DictionaryType
    {
        return $this->intent->certification === null ? null : new DictionaryType(new Entry(new NameType('DocMDP'), new IndirectReference($this->signature)));
    }

    public function sign(PreparedSignature $prepared, ?SignatureOutputVerifier $verifier = null, SignatureSigningEvidence $evidence = new UnavailableSignatureEvidence()): SignedRevision
    {
        if ($prepared->signature !== $this->signature) {
            throw new \InvalidArgumentException('The field signing operation owns this exact reservation.');
        }
        return $prepared->signWithPolicy($this->backend, $this->intent, $verifier, $evidence, $this->urlUsage);
    }
}
