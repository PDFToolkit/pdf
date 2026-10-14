<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

use PDF\Document\Signatures\Policies\SignatureDigestMethod;
use PDF\Security\CmsDerEncoding;

/** RFC 3161 TimeStampReq: the provider receives a digest, not the PDF document. */
final readonly class TimestampRequest
{
    public function __construct(public SignatureDigestMethod $digest, public string $messageImprint, public ?string $policy = null, public ?string $nonce = null)
    {
        if (strlen($messageImprint) !== strlen($digest->digest('')) || ($nonce !== null && ($nonce === '' || strlen($nonce) > 32 || (strlen($nonce) > 1 && $nonce[0] === "\0")))) {
            throw new \InvalidArgumentException('A timestamp request requires the exact digest length and an optional nonnegative, minimal nonce of at most 256 bits.');
        }
        if ($policy !== null) {
            (new CmsDerEncoding())->oid($policy);
        }
    }

    public function der(): string
    {
        $der = new CmsDerEncoding();
        $nonce = $this->nonce;
        return $der->sequence(...["\x02\x01\x01", $der->sequence($der->algorithm($this->digest->oid()), $der->octets($this->messageImprint)),
            ...($this->policy === null ? [] : [$der->oid($this->policy)]),
            ...($nonce === null ? [] : [$der->element(2, (ord($nonce[0]) >= 128 ? "\0" : '') . $nonce)]),
            "\x01\x01\xff",
        ]);
    }
}
