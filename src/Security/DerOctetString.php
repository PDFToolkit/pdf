<?php

declare(strict_types=1);

namespace PDF\Security;

/** The DER OCTET STRING wrapper required by adbe.x509.rsa_sha1 Contents (12.8.3.2). */
final readonly class DerOctetString
{
    public function encode(string $bytes): string
    {
        $length = strlen($bytes);
        if ($length < 128) {
            return "\x04" . chr($length) . $bytes;
        }
        $encodedLength = '';
        while ($length > 0) {
            $encodedLength = chr($length & 255) . $encodedLength;
            $length = intdiv($length, 256);
        }
        return "\x04" . chr(128 + strlen($encodedLength)) . $encodedLength . $bytes;
    }
}
