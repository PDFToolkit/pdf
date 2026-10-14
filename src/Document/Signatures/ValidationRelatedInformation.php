<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

use PDF\Syntax\DataStructure\Date;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;

/** ISO 32000-2 Table 262; successful validation is an explicit external policy decision. */
final class ValidationRelatedInformation extends DictionaryType
{
    public readonly SignatureValidationKey $key;

    public function __construct(SignedRevision $signature, SignatureValidationEvidence $evidence, ?ValidationDataReferences $certificates = null, ?ValidationDataReferences $revocationLists = null, ?ValidationDataReferences $ocspResponses = null, ?Date $created = null, ?TimestampTokenStream $timestamp = null)
    {
        $evidence->assertSuccessful($signature);
        $this->key = new SignatureValidationKey($signature);
        $entries = [new Entry(new NameType('Type'), new NameType('VRI'))];
        foreach ([[ValidationDataKind::Certificate, $certificates], [ValidationDataKind::CertificateRevocationList, $revocationLists], [ValidationDataKind::OnlineCertificateStatus, $ocspResponses]] as [$kind, $references]) {
            if ($references !== null) {
                if ($references->kind !== $kind) {
                    throw new \InvalidArgumentException('The VRI evidence array must have the matching kind.');
                }
                $entries[] = new Entry(new NameType($kind->vriKey()), $references);
            }
        }
        if ($created !== null) {
            $entries[] = new Entry(new NameType('TU'), $created);
        }
        if ($timestamp !== null) {
            $entries[] = new Entry(new NameType('TS'), new IndirectReference($timestamp));
        }
        parent::__construct(...$entries);
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('Construct new validation-related information from the successful policy result.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('Validation-related information is immutable.');
    }
}
