<?php

declare(strict_types=1);

namespace PDF\Document\Signatures\Policies;

use PDF\Specification\Definition\CertificateSeedValueDictionaryTable235Definition;
use PDF\Syntax\DataStructure\AsciiString;
use PDF\Syntax\DataStructure\ByteString;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringType;

/** ISO Table235, PDF457–459: native constraints on facts provided by an external certificate processor. */
final class SignatureCertificateSeedValue extends ImmutableSignatureDictionary
{
    /** @var array<string, list<ByteString>> */
    private readonly array $certificates;
    /** @var list<CertificateSubjectName> */
    private readonly array $subjects;
    /** @var list<CertificatePolicyOid> */
    private readonly array $policies;
    /** @var list<CertificateKeyUsagePattern> */
    private readonly array $usagePatterns;

    public function __construct(public readonly CertificateSeedFlags $flags = new CertificateSeedFlags(), Entry ...$entries)
    {
        $dictionary = new DictionaryType(...$entries);
        if ($dictionary->has('Type') || $dictionary->has('Ff')) {
            throw new \InvalidArgumentException('Certificate seed Type/Ff are constructor-owned.');
        }
        $certificates = [];
        foreach (['Subject', 'Issuer'] as $key) {
            $value = $dictionary->get($key);
            if ($value === null) {
                continue;
            }
            if (!$value instanceof ArrayType) {
                throw new \InvalidArgumentException($key . ' contains DER certificate byte strings.');
            }
            $certificates[$key] = [];
            foreach ($value->items as $certificate) {
                if (!$certificate instanceof StringType || $certificate->value === '') {
                    throw new \InvalidArgumentException('Certificate candidates are nonempty DER byte strings.');
                }
                $certificates[$key][] = new ByteString($certificate->value);
            }
        }
        $this->certificates = $certificates;
        $subjectNames = [];
        $names = $dictionary->get('SubjectDN');
        if ($names !== null && !$names instanceof ArrayType) {
            throw new \InvalidArgumentException('SubjectDN is an array of dictionaries.');
        }
        foreach ($names instanceof ArrayType ? $names->items : [] as $name) {
            if (!$name instanceof DictionaryType) {
                throw new \InvalidArgumentException('Each SubjectDN is a direct attribute dictionary.');
            }
            $subjectNames[] = $name instanceof CertificateSubjectName ? $name : new CertificateSubjectName(...iterator_to_array($name->entries()));
        }
        $this->subjects = $subjectNames;
        $policies = [];
        $oids = $dictionary->get('OID');
        if ($oids !== null) {
            if (!$oids instanceof ArrayType || ($certificates['Issuer'] ?? []) === []) {
                throw new \InvalidArgumentException('Certificate policy OIDs require a nonempty Issuer array.');
            }
            foreach ($oids->items as $oid) {
                if (!$oid instanceof StringType) {
                    throw new \InvalidArgumentException('Certificate policy OIDs are dotted-decimal byte strings.');
                }
                $policies[] = $oid instanceof CertificatePolicyOid ? $oid : new CertificatePolicyOid($oid->value);
            }
        }
        $this->policies = $policies;
        $usagePatterns = [];
        $usage = $dictionary->get('KeyUsage');
        if ($usage !== null && !$usage instanceof ArrayType) {
            throw new \InvalidArgumentException('KeyUsage is an array of ASCII patterns.');
        }
        foreach ($usage instanceof ArrayType ? $usage->items : [] as $pattern) {
            if (!$pattern instanceof StringType) {
                throw new \InvalidArgumentException('A key-usage pattern is an ASCII string.');
            }
            $usagePatterns[] = new CertificateKeyUsagePattern(new AsciiString($pattern->value));
        }
        $this->usagePatterns = $usagePatterns;
        $url = $dictionary->get('URL');
        if ($url !== null) {
            if (!$url instanceof StringType) {
                throw new \InvalidArgumentException('Certificate URL is an ASCII string.');
            }
            new AsciiString($url->value);
        }
        $urlType = $dictionary->get('URLType');
        if ($urlType !== null && (!$urlType instanceof NameType || $urlType->value === '' || $url === null)) {
            throw new \InvalidArgumentException('Certificate URLType is a nonempty purpose name attached to URL.');
        }
        parent::__construct(
            new CertificateSeedValueDictionaryTable235Definition(),
            new Entry(new NameType('Type'), new NameType('SVCert')),
            new Entry(new NameType('Ff'), $flags),
            ...$entries
        );
    }

    public function validateFacts(SignatureCertificateFacts $facts, ?SignatureCertificateUrlUsage $urlUsage = null): void
    {
        if ($this->flags->requires(CertificateSeedFlag::Subject) && isset($this->certificates['Subject']) && !$this->containsCertificate($this->certificates['Subject'], $facts->certificate())) {
            throw new \InvalidArgumentException('The actual signing certificate is not an accepted Subject.');
        }
        if ($this->flags->requires(CertificateSeedFlag::Issuer) && isset($this->certificates['Issuer'])) {
            $accepted = false;
            foreach ($facts->issuers() as $issuer) {
                $accepted = $accepted || $this->containsCertificate($this->certificates['Issuer'], $issuer);
            }
            if (!$accepted) {
                throw new \InvalidArgumentException('The actual certificate chain has no accepted Issuer.');
            }
        }
        if ($this->flags->requires(CertificateSeedFlag::PolicyOid) && $this->has('OID')) {
            $actual = array_map(static fn (CertificatePolicyOid $oid): string => $oid->value, $facts->policies());
            foreach ($this->policies as $policy) {
                if (!in_array($policy->value, $actual, true)) {
                    throw new \InvalidArgumentException('The signing certificate lacks a required policy OID.');
                }
            }
        }
        if ($this->flags->requires(CertificateSeedFlag::SubjectDN)) {
            foreach ($this->subjects as $subject) {
                if (!$facts->containsSubject($subject)) {
                    throw new \InvalidArgumentException('The signing certificate lacks required SubjectDN attributes.');
                }
            }
        }
        if ($this->flags->requires(CertificateSeedFlag::KeyUsage) && $this->has('KeyUsage')) {
            $usage = $facts->keyUsage();
            $accepted = false;
            foreach ($this->usagePatterns as $pattern) {
                $accepted = $accepted || ($usage !== null && $pattern->matches($usage));
            }
            if (!$accepted) {
                throw new \InvalidArgumentException('The actual key-usage extension matches none of the required patterns.');
            }
        }
        $purpose = $this->get('URLType') ?? new NameType('Browser');
        $url = $this->get('URL');
        if ($this->flags->requires(CertificateSeedFlag::URL) && $url instanceof StringType && $purpose instanceof NameType && $purpose->value !== 'Browser') {
            if ($urlUsage === null) {
                throw new \LogicException('A required private certificate URLType needs its explicit external handler.');
            }
            $urlUsage->assertUsage(new AsciiString($url->value), $purpose);
        }
    }

    public function requiresValidation(): bool
    {
        return (new SignatureSeedValidationRequirement())->certificateRequiresValidation($this);
    }

    /** @param list<ByteString> $candidates */
    private function containsCertificate(array $candidates, ByteString $certificate): bool
    {
        foreach ($candidates as $candidate) {
            if (hash_equals($candidate->value, $certificate->value)) {
                return true;
            }
        }
        return false;
    }
}
