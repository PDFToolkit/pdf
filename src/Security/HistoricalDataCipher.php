<?php

declare(strict_types=1);

namespace PDF\Security;

/** Crypt filter methods from ISO table 25, PDF page 76. */
enum HistoricalDataCipher: string
{
    case Rc4 = 'V2';
    case Aes128 = 'AESV2';
}
