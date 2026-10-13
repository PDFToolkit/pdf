<?php

declare(strict_types=1);

namespace PDF\Document\Signatures\Policies;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;

/** Detects seed obligations, including dictionaries constructed through the generated schema API. */
final readonly class SignatureSeedValidationRequirement
{
    public function requiresValidation(DictionaryType $seed): bool
    {
        $flags = $seed->get('Ff');
        if ($flags !== null && (!$flags instanceof IntegerType || $flags->integer() < 0 || ($flags->integer() & ~511) !== 0)) {
            return true;
        }
        $bits = $flags instanceof IntegerType ? $flags->integer() : 0;
        // Reasons is exceptional: setting its bit while omitting Reasons requires omission of Reason.
        if (($bits & SignatureSeedFlag::Reasons->value) !== 0) {
            return true;
        }
        foreach ([
            [SignatureSeedFlag::Filter, 'Filter'],
            [SignatureSeedFlag::ParserCapability, 'V'],
            [SignatureSeedFlag::LegalAttestation, 'LegalAttestation'],
            [SignatureSeedFlag::Revocation, 'AddRevInfo'],
            [SignatureSeedFlag::DigestMethod, 'DigestMethod'],
            [SignatureSeedFlag::AppearanceFilter, 'AppearanceFilter'],
        ] as [$flag, $key]) {
            if (($bits & $flag->value) !== 0 && $seed->has($key)) {
                return true;
            }
        }
        $formats = $seed->get('SubFilter');
        if ($formats !== null && (!$formats instanceof ArrayType || $formats->items !== [] || ($bits & SignatureSeedFlag::SubFilter->value) !== 0)) {
            // Even an optional list requires the first supported preferred encoding to be selected.
            return true;
        }
        $lock = $seed->get('LockDocument');
        if (($bits & SignatureSeedFlag::LockDocument->value) !== 0 && $lock !== null && (!$lock instanceof NameType || $lock->value !== 'auto')) {
            return true;
        }
        $mdp = $seed->get('MDP');
        if ($mdp !== null && (!$mdp instanceof DictionaryType || $mdp->has('P'))) {
            return true;
        }
        $timestamp = $seed->get('TimeStamp');
        if ($timestamp !== null) {
            if (!$timestamp instanceof DictionaryType) {
                return true;
            }
            $required = $timestamp->get('Ff');
            if ($required !== null && (!$required instanceof IntegerType || $required->integer() !== 0)) {
                return true;
            }
        }
        $certificate = $seed->get('Cert');
        return $certificate !== null && (!$certificate instanceof DictionaryType || $this->certificateRequiresValidation($certificate));
    }

    public function certificateRequiresValidation(DictionaryType $seed): bool
    {
        $flags = $seed->get('Ff');
        if ($flags !== null && (!$flags instanceof IntegerType || $flags->integer() < 0 || ($flags->integer() & ~111) !== 0)) {
            return true;
        }
        $bits = $flags instanceof IntegerType ? $flags->integer() : 0;
        foreach ([
            [CertificateSeedFlag::Subject, 'Subject'],
            [CertificateSeedFlag::Issuer, 'Issuer'],
            [CertificateSeedFlag::PolicyOid, 'OID'],
            [CertificateSeedFlag::SubjectDN, 'SubjectDN'],
            [CertificateSeedFlag::KeyUsage, 'KeyUsage'],
        ] as [$flag, $key]) {
            if (($bits & $flag->value) !== 0 && $seed->has($key)) {
                return true;
            }
        }
        $purpose = $seed->get('URLType');
        return ($bits & CertificateSeedFlag::URL->value) !== 0 && $seed->has('URL') && $purpose !== null && (!$purpose instanceof NameType || $purpose->value !== 'Browser');
    }
}
