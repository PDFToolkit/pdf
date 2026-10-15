<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

use PDF\Security\CertificateChain;
use PDF\Syntax\FileStructure\FileRevision;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringType;

/** Actual mathematical verification of one final native revision; never a trust decision. */
final readonly class VerifiedRevisionSignature
{
    private string $revisionHash;
    public VerifiedSignatureFacts $facts;

    public function __construct(
        FileRevision $revision,
        public ObjectIdentifier $identifier,
        public SignatureByteRange $range,
        private SignatureValue $value,
        private SignatureFormat $format,
        private CertificateChain $certificate,
        SignatureOutputVerifier $verifier,
    ) {
        if (!$revision->isNativeEmission()) {
            throw new \LogicException('Signature history verification requires a native emitted revision, not caller-supplied snapshots.');
        }
        $this->revisionHash = hash('sha256', $revision->bytes, true);
        $this->validateBinding($revision);
        $input = new SignatureVerificationInput($range->coveredBytes($revision->bytes), $value, $format, $certificate);
        $facts = $verifier->verify($input);
        $facts->validateBinding($input);
        $this->facts = $facts;
    }

    public function validateBinding(FileRevision $revision): void
    {
        if (!hash_equals($this->revisionHash, hash('sha256', $revision->bytes, true))
            || $this->range->afterOffset + $this->range->afterLength !== strlen($revision->bytes)) {
            throw new \InvalidArgumentException('Verified signature history belongs to the exact complete final revision bytes.');
        }
        $dictionary = $revision->binding($this->identifier)->dictionary;
        $contents = $dictionary?->get('Contents');
        $format = $dictionary?->get('SubFilter');
        $range = $dictionary?->get('ByteRange');
        $expected = $this->range->object();
        if (!$contents instanceof StringType || !str_starts_with($contents->value, $this->value->bytes)
            || substr($contents->value, strlen($this->value->bytes)) !== str_repeat("\0", strlen($contents->value) - strlen($this->value->bytes))
            || !$format instanceof NameType || $format->value !== $this->format->value || !$range instanceof ArrayType || count($range->items) !== 4) {
            throw new \InvalidArgumentException('Verified signature history matches its actual emitted dictionary and padded Contents.');
        }
        foreach ($range->items as $index => $item) {
            if (!$item instanceof IntegerType || !$expected->items[$index] instanceof IntegerType || $item->integer() !== $expected->items[$index]->integer()) {
                throw new \InvalidArgumentException('Verified signature history matches the exact emitted ByteRange.');
            }
        }
        if ($this->format === SignatureFormat::X509RsaSha1) {
            $actual = $dictionary->get('Cert');
            $certificates = $actual instanceof ArrayType ? $actual->items : [$actual];
            $expectedCertificates = $this->certificate->object()->items;
            if (count($certificates) !== count($expectedCertificates)) {
                throw new \InvalidArgumentException('Verified PKCS#1 history identifies the actual emitted certificate chain.');
            }
            foreach ($certificates as $index => $certificate) {
                $expectedCertificate = $expectedCertificates[$index];
                if (!$certificate instanceof StringType || !$expectedCertificate instanceof StringType || !hash_equals($certificate->value, $expectedCertificate->value)) {
                    throw new \InvalidArgumentException('Verified PKCS#1 history agrees with the emitted signing credential.');
                }
            }
        }
        $token = '<' . strtoupper(bin2hex($contents->value)) . '>';
        $span = $revision->span($this->identifier);
        if ($this->range->before < $span->offset->value || $this->range->afterOffset > $span->offset->value + $span->length
            || substr($revision->bytes, $this->range->before, $this->range->afterOffset - $this->range->before) !== $token) {
            throw new \InvalidArgumentException('The sole unsigned gap is this exact signature Contents token.');
        }
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new \LogicException('Verified native signature evidence cannot be serialized.');
    }

    /** @param array<mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('Verified native signature evidence cannot be restored from caller data.');
    }
}
