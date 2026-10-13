<?php

declare(strict_types=1);

namespace PDF\Document\Signatures\Policies;

/** Table253 retained values; SHA1 avoids the deprecated MD5 default for modern references. */
enum SignatureReferenceDigest: string
{
    case MD5 = 'MD5';
    case SHA1 = 'SHA1';
}
