<?php

declare(strict_types=1);

namespace PDF\Document\Signatures\Policies;

use PDF\Syntax\DataStructure\ByteString;

/** Table235 dotted-decimal OID byte string, matching its explicit PDF example. */
final class CertificatePolicyOid extends ByteString
{
    public function __construct(string $value)
    {
        if (preg_match('/^[0-2]\\.(?:0|[1-9][0-9]*)(?:\\.(?:0|[1-9][0-9]*))*$/D', $value) !== 1) {
            throw new \InvalidArgumentException('A policy OID has canonical dotted-decimal arcs.');
        }
        $arcs = explode('.', $value);
        if ($arcs[0] !== '2' && (strlen($arcs[1]) > 2 || (int) $arcs[1] > 39)) {
            throw new \InvalidArgumentException('OID second arc is0–39 when the first arc is0 or1.');
        }
        parent::__construct($value);
    }
}
