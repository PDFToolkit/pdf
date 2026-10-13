<?php

declare(strict_types=1);

namespace PDF\Document\Signatures\Policies;

use PDF\Syntax\DataStructure\AsciiString;
use PDF\Syntax\Object\NameType;

/** Explicit handler for a third-party URLType; standard Browser ignores the URL mandatory bit. */
interface SignatureCertificateUrlUsage
{
    public function assertUsage(AsciiString $url, NameType $purpose): void;
}
