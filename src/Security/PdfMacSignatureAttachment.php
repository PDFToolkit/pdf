<?php

declare(strict_types=1);

namespace PDF\Security;

use PDF\Document\Signatures\SignatureValue;

/** TS 32004 6.5.2, the MAC covers ByteRange and the raw SignerInfo signature octets. */
final readonly class PdfMacSignatureAttachment
{
    public function attach(SignatureValue $signature, string $coveredBytes, PdfMacTokenComposer $composer): SignatureValue
    {
        [$outer, $data, $signer, $signatureIndex] = $this->parts($signature);
        $der = new CmsDerEncoding();
        $attributes = [];
        $unsignedIndex = $signatureIndex + 1;
        if (isset($signer[$unsignedIndex])) {
            $attributes = $signer[$unsignedIndex]->requireTag(161)->children();
        }
        foreach ($attributes as $attribute) {
            $fields = $attribute->requireTag(48)->children();
            if ($fields !== [] && $fields[0]->encoding === $der->oid('1.0.32004.1.2')) {
                throw new \InvalidArgumentException('A signature cannot carry two PDF MAC unsigned attributes.');
            }
        }
        $values = array_map(static fn (SignatureDerElement $attribute): string => $attribute->encoding, $attributes);
        $values[] = $der->sequence($der->oid('1.0.32004.1.2'), $der->set($composer->token($coveredBytes, $signer[$signatureIndex]->value)));
        $set = (new SignatureDerReader())->element($der->set(...$values));
        $signerBytes = array_map(static fn (SignatureDerElement $part): string => $part->encoding, array_slice($signer, 0, $unsignedIndex));
        $signerBytes[] = $der->element(161, $set->value);
        $dataBytes = array_map(static fn (SignatureDerElement $part): string => $part->encoding, array_slice($data, 0, -1));
        $dataBytes[] = $der->set($der->sequence(...$signerBytes));
        return new SignatureValue($der->sequence($outer[0]->encoding, $der->element(160, $der->sequence(...$dataBytes))));
    }

    public function signatureValue(SignatureValue $signature): string
    {
        [, , $signer, $index] = $this->parts($signature);
        return $signer[$index]->value;
    }

    public function verify(SignatureValue $signature, string $coveredBytes, PdfMacTokenComposer $composer): void
    {
        [, , $signer, $index] = $this->parts($signature);
        $attrs = isset($signer[$index + 1]) ? $signer[$index + 1]->requireTag(161)->children() : [];
        $found = false;
        foreach ($attrs as $attribute) {
            $fields = $attribute->requireTag(48)->children();
            if ($fields !== [] && $fields[0]->encoding === (new CmsDerEncoding())->oid('1.0.32004.1.2')) {
                $values = count($fields) === 2 ? $fields[1]->requireTag(49)->children() : [];
                if ($found || count($values) !== 1) {
                    throw new \UnexpectedValueException('The PDF MAC unsigned attribute has exactly one value and occurs once.');
                }
                $composer->verify($values[0]->encoding, $coveredBytes, $signer[$index]->value);
                $found = true;
            }
        }
        if (!$found) {
            throw new \UnexpectedValueException('The signature has no PDF MAC unsigned attribute.');
        }
    }

    /** @return array{list<SignatureDerElement>,list<SignatureDerElement>,list<SignatureDerElement>,int} */
    private function parts(SignatureValue $signature): array
    {
        $outer = (new SignatureDerReader())->element($signature->bytes)->requireTag(48)->children();
        if (count($outer) !== 2 || $outer[0]->encoding !== (new CmsDerEncoding())->oid('1.2.840.113549.1.7.2')) {
            throw new \InvalidArgumentException('PDF MAC attachment requires a CMS SignedData ContentInfo.');
        }
        $wrapped = $outer[1]->requireTag(160)->children();
        $data = count($wrapped) === 1 ? $wrapped[0]->requireTag(48)->children() : [];
        $signers = $data === [] ? [] : $data[array_key_last($data)]->requireTag(49)->children();
        if (count($signers) !== 1) {
            throw new \InvalidArgumentException('A PDF MAC attachment requires exactly one CMS SignerInfo.');
        }
        $signer = $signers[0]->requireTag(48)->children();
        $signatureIndex = isset($signer[3]) && $signer[3]->tag === 160 ? 5 : 4;
        if (count($signer) < $signatureIndex + 1 || count($signer) > $signatureIndex + 2) {
            throw new \InvalidArgumentException('The CMS SignerInfo has an invalid signature or unsigned attributes layout.');
        }
        $signer[$signatureIndex]->requireTag(4);
        return [$outer, $data, $signer, $signatureIndex];
    }
}
