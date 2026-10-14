<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

use PDF\Syntax\Filters\Filter;
use PDF\Syntax\Object\StreamObject;

/** ISO 32000-2 Table 262 TS: DER-encoded timestamp token in an indirect stream. */
final class TimestampTokenStream extends StreamObject
{
    public function __construct(public readonly TimestampToken $token, Filter ...$filters)
    {
        parent::__construct($token->bytes, null, ...$filters);
    }
}
