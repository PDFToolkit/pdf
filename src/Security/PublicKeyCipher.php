<?php

declare(strict_types=1);

namespace PDF\Security;

enum PublicKeyCipher: string
{
    case Rc4 = 'V2';
    case Aes128 = 'AESV2';
    case Aes256 = 'AESV3';
}
