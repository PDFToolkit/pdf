<?php

declare(strict_types=1);

namespace PDF\Security;

enum ModernSecurityRevision: int
{
    case Aes256Cbc = 6;
    case Aes256Gcm = 7;
}
