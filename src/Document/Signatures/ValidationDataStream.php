<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

use PDF\Security\SignatureDerReader;
use PDF\Syntax\Filters\Filter;
use PDF\Syntax\Object\StreamObject;

/** ISO 32000-2 Tables 261/262: retained DER evidence, with no implicit PKI trust. */
final class ValidationDataStream extends StreamObject
{
    public function __construct(public readonly ValidationDataKind $kind, string $der, Filter ...$filters)
    {
        (new SignatureDerReader())->element($der)->requireTag(48);
        parent::__construct($der, null, ...$filters);
    }
}
