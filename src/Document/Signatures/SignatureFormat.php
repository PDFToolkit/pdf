<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

/** Standard public-key SubFilter formats, PDF32000_2008.pdf, 12.8.3, PDF pages 483–486. */
enum SignatureFormat: string
{
    case Pkcs7Detached = 'adbe.pkcs7.detached';
    case Pkcs7Sha1 = 'adbe.pkcs7.sha1';
    case X509RsaSha1 = 'adbe.x509.rsa_sha1';
    case Rfc3161 = 'ETSI.RFC3161';
}
