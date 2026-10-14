<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

use PDF\Syntax\FileStructure\FileRevision;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PDF\Serialization\SerializationContext;
use PDF\Document\Signatures\Policies\SignatureSeedValidationRequirement;
use PDF\Document\Signatures\Policies\SignatureSigningEvidence;
use PDF\Document\Signatures\Policies\UnavailableSignatureEvidence;
use PDF\Document\Signatures\Policies\SignatureCertificateUrlUsage;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\StringType;
use PDF\Security\PdfMacTokenComposer;
use PDF\Security\PdfMacSignatureAttachment;

/** Exact byte reservations identified from the writer's object spans, never by scanning arbitrary PDF bytes. */
final readonly class PreparedSignature
{
    public SignatureByteRange $range;
    private int $byteRangeOffset;
    public string $bytes;

    public function __construct(public FileRevision $revision, public PendingSignatureDictionary $signature, private ?PdfMacTokenComposer $mac = null)
    {
        (new \PDF\Syntax\FileStructure\EffectivePdfVersion(
            $revision->header,
            $revision->binding($revision->root->target())->dictionary ?? throw new \LogicException('Signature revision has no catalog.')
        ))
            ->require(\PDF\Syntax\FileStructure\PdfVersion::PDF13, 'Digital signatures');
        $binding = $revision->binding($signature);
        if ($binding->value !== $signature) {
            throw new \InvalidArgumentException('Only an unfinalized signature reservation can be prepared.');
        }
        $authCode = $revision->trailerEntries->get('AuthCode');
        if ($authCode !== null) {
            $location = $authCode instanceof DictionaryType ? $authCode->get('MACLocation') : null;
            $target = $authCode instanceof DictionaryType ? $authCode->get('SigObjRef') : null;
            if (!$location instanceof NameType || $location->value !== 'AttachedToSig' || !$target instanceof IndirectReference
                || (string) $revision->binding($target->target())->identifier !== (string) $binding->identifier || $mac === null) {
                throw new \LogicException('Signing a PDF MAC revision requires its selected signature and an explicit MAC composer.');
            }
            $mac->validateEncryption($revision->encryption ?? throw new \LogicException('PDF MAC requires file encryption.'));
        } elseif ($mac !== null) {
            throw new \LogicException('The PDF MAC signature location must be prepared before any file bytes are emitted.');
        }
        $span = $revision->span($binding->identifier);
        $prefix = $span->identifier . " obj\n<< /Contents ";
        $contents = $signature->contents->toPdf(new SerializationContext());
        $range = $signature->byteRange->toPdf(new SerializationContext());
        $reservation = $prefix . $contents . ' /ByteRange ' . $range;
        if (substr($revision->bytes, $span->offset->value, strlen($reservation)) !== $reservation) {
            throw new \InvalidArgumentException('The exact signature reservation is absent from its generated object span.');
        }
        $before = $span->offset->value + strlen($prefix);
        $after = $before + strlen($contents);
        $this->range = new SignatureByteRange($before, $after, strlen($revision->bytes) - $after);
        $this->byteRangeOffset = $after + strlen(' /ByteRange ');
        $this->bytes = substr_replace($revision->bytes, $signature->byteRange->filled($this->range), $this->byteRangeOffset, strlen($range));
        $graph = new FrozenSignatureGraph($revision);
        if ($signature instanceof PendingDocumentTimestamp && count($graph->bindings($binding->identifier)) !== 1) {
            throw new \LogicException('A document timestamp belongs to one actual signature field.');
        }
        (new SignatureDocumentSigningRules())->validate($graph, $binding->identifier);
        $history = new SignaturePermissionHistory($graph);
        foreach ($graph->bindings($binding->identifier) as $field) {
            if ($signature instanceof PendingDocumentTimestamp) {
                $history->assertDocumentTimestamp($graph, $field, $signature);
            } else {
                $history->assertSigning($field);
            }
        }
    }

    public function coveredBytes(): string
    {
        return $this->range->coveredBytes($this->bytes);
    }

    public function sign(SignatureBackend $backend): SignedRevision
    {
        $this->validateFieldObligations();
        $this->validateCatalog($backend);
        if ($backend instanceof VersionedSignatureBackend) {
            $backend->validateVersion(new \PDF\Syntax\FileStructure\EffectivePdfVersion(
                $this->revision->header,
                $this->revision->binding($this->revision->root->target())->dictionary ?? throw new \LogicException('Signature revision has no catalog.')
            ), $this->signature->format);
        }
        if ($this->signature->format === SignatureFormat::X509RsaSha1 && $backend instanceof CertificateAwareSignatureBackend) {
            $certificate = $this->revision->binding($this->signature)->dictionary?->get('Cert');
            $signer = $certificate instanceof ArrayType ? ($certificate->items[0] ?? null) : $certificate;
            if (!$signer instanceof StringType || !hash_equals($backend->certificateChain()->signer->value->value, $signer->value)) {
                throw new \InvalidArgumentException('The signature Cert must match the certificate of the actual signing key.');
            }
        }
        return $this->finalize($backend->sign($this->coveredBytes(), $this->signature->format));
    }

    /** External/hardware signature handlers can finalize the same immutable prepared bytes. */
    public function finalize(SignatureValue $value): SignedRevision
    {
        $this->validateFieldObligations();
        return $this->finalizeValidated($value);
    }

    public function signWithPolicy(
        PolicySignatureBackend $backend,
        SignatureSigningIntent $intent = new SignatureSigningIntent(),
        ?SignatureOutputVerifier $verifier = null,
        SignatureSigningEvidence $evidence = new UnavailableSignatureEvidence(),
        ?SignatureCertificateUrlUsage $urlUsage = null
    ): SignedRevision {
        $this->validateCatalog($backend);
        $contract = new SignatureFieldPolicyContract($this, $backend, $intent, $urlUsage);
        $value = $backend->signWithDigest($this->coveredBytes(), $this->signature->format, $contract->choice->digest);
        $contract->validateOutput($value, $verifier ?? $backend->outputVerifier(), $evidence);
        return $this->finalizeValidated($value);
    }

    public function finalizeWithPolicy(
        SignatureValue $value,
        PolicySignatureBackend $backend,
        SignatureSigningIntent $intent = new SignatureSigningIntent(),
        ?SignatureOutputVerifier $verifier = null,
        SignatureSigningEvidence $evidence = new UnavailableSignatureEvidence(),
        ?SignatureCertificateUrlUsage $urlUsage = null
    ): SignedRevision {
        $this->validateCatalog($backend);
        $contract = new SignatureFieldPolicyContract($this, $backend, $intent, $urlUsage);
        $contract->validateOutput($value, $verifier ?? $backend->outputVerifier(), $evidence);
        return $this->finalizeValidated($value);
    }

    private function validateCatalog(SignatureBackend $backend): void
    {
        if ($backend instanceof CatalogSignatureBackend) {
            $backend->validateCatalog($this->revision->binding($this->revision->root->target())->dictionary
                ?? throw new \LogicException('Signature revision has no frozen catalog.'));
        }
    }

    private function finalizeValidated(SignatureValue $value): SignedRevision
    {
        if ($this->mac !== null) {
            $attachment = new PdfMacSignatureAttachment();
            $value = $attachment->attach($value, $this->coveredBytes(), $this->mac);
            $attachment->verify($value, $this->coveredBytes(), $this->mac);
        }
        if (strlen($value->bytes) > $this->signature->capacity->bytes) {
            throw new \LengthException('Signature output exceeds its reserved capacity; prepare a new reservation and sign again.');
        }
        $padded = str_pad($value->bytes, $this->signature->capacity->bytes, "\0");
        $bytes = substr_replace(
            $this->bytes,
            '<' . strtoupper(bin2hex($padded)) . '>',
            $this->range->before,
            $this->range->afterOffset - $this->range->before
        );
        $dictionary = $this->revision->binding($this->signature)->dictionary
            ?? throw new \LogicException('Finalization requires the dictionary actually emitted for this reservation.');
        $dictionary = $dictionary->with(new Entry(new NameType('Contents'), new HexadecimalString($padded)))
            ->with(new Entry(new NameType('ByteRange'), $this->range->object()));
        $record = $this->revision->binding($this->signature)->replacedBy($dictionary);
        return new SignedRevision($this->revision->withReservedBytes($bytes, $record), $this->range, $value, $this->signature->format);
    }

    /** Mandatory field policies cannot silently be bypassed by the low-level byte-range operation. */
    private function validateFieldObligations(): void
    {
        $identifier = $this->revision->binding($this->signature)->identifier;
        $graph = new FrozenSignatureGraph($this->revision);
        foreach ($graph->bindings($identifier) as $field) {
            if ($field->dictionary->has('Lock')) {
                throw new \LogicException('Signing a locked field requires FieldMDP orchestration; the byte-range operation cannot ignore it.');
            }
            $seed = $field->dictionary->get('SV');
            if ($seed === null) {
                continue;
            }
            $seed = $graph->resolve($seed);
            if (!$seed instanceof DictionaryType || (new SignatureSeedValidationRequirement())->requiresValidation($seed)) {
                throw new \LogicException('Signature seed obligations require a validated signing contract; the byte-range operation cannot ignore them.');
            }
        }
    }
}
