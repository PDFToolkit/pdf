<?php

declare(strict_types=1);

namespace PDF\Document\Signatures\Policies;

use PDF\Document\CatalogComponent;
use PDF\Document\Signatures\SignatureFormat;
use PDF\Document\Signatures\SignatureValue;
use PDF\Document\VersionedComponent;
use PDF\Specification\Adobe\Definition\SignatureFieldSeedValueDictionaryTable8_83Definition;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\RealType;

/** Table234 and Adobe Table8.83: executable native choices, explicit external certificate/evidence contracts. */
final class SignatureSeedValue extends ImmutableSignatureDictionary implements CatalogComponent, VersionedComponent
{
    /** @var list<NameType>|null */
    private readonly ?array $formats;
    /** @var list<SignatureDigestMethod>|null */
    private readonly ?array $digests;
    /** @var array<string, list<TextString>> */
    private readonly array $textChoices;
    public readonly ?SignatureCertificateSeedValue $certificate;
    public readonly ?SignatureSeedMdp $mdp;
    public readonly ?SignatureTimeStampSeed $timeStamp;
    public readonly bool $addRevocation;

    public function __construct(public readonly SignatureSeedFlags $flags = new SignatureSeedFlags(), Entry ...$entries)
    {
        $dictionary = new DictionaryType(...$entries);
        if ($dictionary->has('Type') || $dictionary->has('Ff')) {
            throw new \InvalidArgumentException('Seed Type/Ff are constructor-owned.');
        }
        $filter = $dictionary->get('Filter');
        if ($filter !== null && (!$filter instanceof NameType || $filter->value === '')) {
            throw new \InvalidArgumentException('Seed Filter is a nonempty handler name.');
        }
        $formats = null;
        $subfilter = $dictionary->get('SubFilter');
        if ($subfilter !== null) {
            if (!$subfilter instanceof ArrayType) {
                throw new \InvalidArgumentException('SubFilter is an ordered array of encoding names.');
            }
            $formats = [];
            foreach ($subfilter->items as $format) {
                if (!$format instanceof NameType || $format->value === '') {
                    throw new \InvalidArgumentException('Every SubFilter preference is a nonempty name.');
                }
                $formats[] = $format;
            }
        }
        $this->formats = $formats;
        $digests = null;
        $digest = $dictionary->get('DigestMethod');
        if ($digest !== null) {
            if (!$digest instanceof ArrayType) {
                throw new \InvalidArgumentException('DigestMethod is an array of names.');
            }
            $digests = [];
            foreach ($digest->items as $method) {
                if (!$method instanceof NameType || SignatureDigestMethod::tryFrom($method->value) === null) {
                    throw new \InvalidArgumentException('DigestMethod selects a Table234 RSA digest.');
                }
                $digests[] = SignatureDigestMethod::from($method->value);
            }
        }
        $this->digests = $digests;
        $version = $dictionary->get('V');
        if ($version !== null) {
            if (!$version instanceof NumericType || $version->value < 0) {
                throw new \InvalidArgumentException('Seed V is a nonnegative real parser capability.');
            }
            $dictionary = $dictionary->with(new Entry(new NameType('V'), new RealType($version->value)));
        }
        $choices = [];
        foreach (['Reasons', 'LegalAttestation'] as $key) {
            $value = $dictionary->get($key);
            if ($value === null) {
                continue;
            }
            if (!$value instanceof ArrayType) {
                throw new \InvalidArgumentException($key . ' is an array of text strings.');
            }
            $choices[$key] = [];
            foreach ($value->items as $text) {
                if (!$text instanceof TextString) {
                    throw new \InvalidArgumentException('Seed text choices are explicit TextString objects.');
                }
                $choices[$key][] = $text;
            }
        }
        $this->textChoices = $choices;
        $cert = $dictionary->get('Cert');
        if ($cert !== null && !$cert instanceof SignatureCertificateSeedValue) {
            throw new \InvalidArgumentException('Cert is an explicit certificate seed policy.');
        }
        $this->certificate = $cert;
        $mdp = $dictionary->get('MDP');
        if ($mdp !== null && !$mdp instanceof SignatureSeedMdp) {
            throw new \InvalidArgumentException('MDP is an explicit seed permissions object.');
        }
        $this->mdp = $mdp;
        $timeStamp = $dictionary->get('TimeStamp');
        if ($timeStamp !== null && !$timeStamp instanceof SignatureTimeStampSeed) {
            throw new \InvalidArgumentException('TimeStamp is an explicit timestamp-server/evidence policy.');
        }
        $this->timeStamp = $timeStamp;
        $revocation = $dictionary->get('AddRevInfo');
        if ($revocation !== null && !$revocation instanceof BooleanType) {
            throw new \InvalidArgumentException('AddRevInfo is a boolean.');
        }
        $this->addRevocation = $revocation->value ?? false;
        $lock = $dictionary->get('LockDocument');
        if ($lock !== null && (!$lock instanceof NameType || SignatureDocumentLock::tryFrom($lock->value) === null)) {
            throw new \InvalidArgumentException('LockDocument is the name true, false or auto.');
        }
        $appearance = $dictionary->get('AppearanceFilter');
        if ($appearance !== null && !$appearance instanceof TextString) {
            throw new \InvalidArgumentException('AppearanceFilter is the text name of an explicit signature appearance.');
        }
        parent::__construct(new SignatureFieldSeedValueDictionaryTable8_83Definition(), new Entry(new NameType('Type'), new NameType('SV')), new Entry(new NameType('Ff'), $flags), ...iterator_to_array($dictionary->entries()));
    }

    /** Table234: the first supported preferred encoding must be selected, even for optional preferences. */
    public function selectFormat(SignatureHandlerCapabilities $capabilities): SignatureFormat
    {
        $this->assertHandler($capabilities);
        foreach ($this->formats ?? [] as $name) {
            $format = SignatureFormat::tryFrom($name->value);
            if ($format !== null && $capabilities->supports($format)) {
                return $format;
            }
        }
        if ($this->formats !== null && $this->flags->requires(SignatureSeedFlag::SubFilter)) {
            throw new \InvalidArgumentException('No supported encoding satisfies the required SubFilter seed.');
        }
        return $capabilities->formats[0];
    }

    public function validateChoice(SignatureSigningChoice $choice, SignatureCertificateFacts $certificate, ?SignatureCertificateUrlUsage $urlUsage = null): void
    {
        if ($choice->format !== $this->selectFormat($choice->capabilities)) {
            throw new \InvalidArgumentException('Signing must select the first supported preferred SubFilter.');
        }
        $version = $this->get('V');
        if ($version instanceof NumericType && $this->flags->requires(SignatureSeedFlag::ParserCapability) && $choice->capabilities->seedParserCapability->value < $version->value) {
            throw new \InvalidArgumentException('The selected handler cannot recognize the required seed parser version.');
        }
        if ($certificate->keyAlgorithm() === SignatureKeyAlgorithm::DSA && $choice->digest !== SignatureDigestMethod::SHA1) {
            throw new \InvalidArgumentException('ISO seed digest rules require SHA1 for DSA credentials.');
        }
        if ($certificate->keyAlgorithm() === SignatureKeyAlgorithm::RSA && $this->digests !== null && $this->flags->requires(SignatureSeedFlag::DigestMethod) && !in_array($choice->digest, $this->digests, true)) {
            throw new \InvalidArgumentException('The selected RSA digest violates the required DigestMethod seed.');
        }
        if ($choice->format === SignatureFormat::X509RsaSha1 && $certificate->keyAlgorithm() !== SignatureKeyAlgorithm::RSA) {
            throw new \InvalidArgumentException('The PKCS#1 x509.rsa_sha1 format requires RSA credentials.');
        }
        if ($this->flags->requires(SignatureSeedFlag::Reasons)) {
            $reasons = $this->textChoices['Reasons'] ?? null;
            $omit = $reasons === null || (count($reasons) === 1 && $reasons[0]->text === '.');
            if ($omit ? $choice->reason !== null : !$this->containsText($reasons, $choice->reason)) {
                throw new \InvalidArgumentException('The actual signature Reason violates the required seed rule.');
            }
        }
        if ($this->flags->requires(SignatureSeedFlag::LegalAttestation) && isset($this->textChoices['LegalAttestation']) && !$this->containsText($this->textChoices['LegalAttestation'], $choice->legalAttestation)) {
            throw new \InvalidArgumentException('The selected legal attestation violates the required seed.');
        }
        $permissions = $this->mdp?->permissions;
        if ($permissions !== null && $permissions->integer() !== ($choice->certification->value ?? 0)) {
            throw new \InvalidArgumentException('The actual certification permission differs from seed MDP P.');
        }
        $lock = $this->get('LockDocument');
        if ($this->flags->requires(SignatureSeedFlag::LockDocument) && $lock instanceof NameType && $lock->value !== 'auto' && $choice->documentLock?->value !== $lock->value) {
            throw new \InvalidArgumentException('The actual document-lock choice violates the required seed.');
        }
        $appearance = $this->get('AppearanceFilter');
        if ($this->flags->requires(SignatureSeedFlag::AppearanceFilter) && $appearance instanceof TextString && $choice->appearance?->text !== $appearance->text) {
            throw new \InvalidArgumentException('The required named signature appearance is unavailable or not selected.');
        }
        $this->assertEvidenceChoice($choice);
        $this->certificate?->validateFacts($certificate, $urlUsage);
    }

    public function validateEvidence(SignatureSigningChoice $choice, SignatureValue $value, SignatureCertificateFacts $certificate, SignatureSigningEvidence $evidence): void
    {
        $this->assertEvidenceChoice($choice);
        if ($choice->includeTimeStamp) {
            $evidence->assertTimeStamp($this->timeStamp, $value);
        }
        if ($choice->includeRevocation) {
            $evidence->assertRevocation($certificate, $value);
        }
    }

    public function requiresValidation(): bool
    {
        return (new SignatureSeedValidationRequirement())->requiresValidation($this);
    }

    private function assertEvidenceChoice(SignatureSigningChoice $choice): void
    {
        if ($this->has('AddRevInfo') && $this->flags->requires(SignatureSeedFlag::Revocation) && $choice->includeRevocation !== $this->addRevocation) {
            throw new \InvalidArgumentException('The revocation choice differs from the required AddRevInfo seed.');
        }
        if ($this->timeStamp?->required && !$choice->includeTimeStamp) {
            throw new \InvalidArgumentException('The required timestamp was not selected for this signature.');
        }
        if ($choice->includeRevocation && $choice->format === SignatureFormat::X509RsaSha1) {
            throw new \InvalidArgumentException('AddRevInfo true is not permitted with the PKCS#1 subfilter.');
        }
    }

    private function assertHandler(SignatureHandlerCapabilities $capabilities): void
    {
        $filter = $this->get('Filter');
        if ($filter instanceof NameType && $this->flags->requires(SignatureSeedFlag::Filter) && $filter->value !== $capabilities->handler->value) {
            throw new \InvalidArgumentException('The required signature handler is unavailable or was not selected.');
        }
    }

    /** @param list<TextString> $candidates */
    private function containsText(array $candidates, ?TextString $text): bool
    {
        foreach ($candidates as $candidate) {
            if ($text !== null && $candidate->text === $text->text) {
                return true;
            }
        }
        return false;
    }

    public function validateCatalog(DictionaryType $catalog): void
    {
        $version = $this->get('V');
        if (!$this->has('LockDocument') && !$this->has('AppearanceFilter') && ($this->flags->integer() & 384) === 0 && (!$version instanceof NumericType || $version->value < 3)) {
            return;
        }
        $extensions = $catalog->get('Extensions');
        $adobe = $extensions instanceof DictionaryType ? $extensions->get('ADBE') : null;
        $base = $adobe instanceof DictionaryType ? $adobe->get('BaseVersion') : null;
        $level = $adobe instanceof DictionaryType ? $adobe->get('ExtensionLevel') : null;
        if (!$base instanceof NameType || $base->value !== '1.7' || !$level instanceof IntegerType || $level->integer() < 3) {
            throw new \InvalidArgumentException('Adobe signature seeds require direct ADBE BaseVersion1.7, ExtensionLevel3 or higher.');
        }
    }

    public function validateVersion(EffectivePdfVersion $version): void
    {
        $version->require(PdfVersion::PDF15, 'Signature seed values');
        $parser = $this->get('V');
        if ($this->has('LockDocument') || $this->has('AppearanceFilter') || ($this->flags->integer() & 384) !== 0 || ($parser instanceof NumericType && $parser->value >= 3)) {
            $version->require(PdfVersion::PDF17, 'Adobe signature seed extensions');
        }
    }
}
