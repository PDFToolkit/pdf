<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

/** Explicit handler boundary: external CMS/PKCS#1 processing is not PDF serialization. */
interface SignatureBackend
{
    public function sign(string $coveredBytes, SignatureFormat $format): SignatureValue;
}
