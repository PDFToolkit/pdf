<?php

declare(strict_types=1);

namespace PDF\Security;

enum PublicKeySubFilter: string
{
    case Pkcs7S3 = 'adbe.pkcs7.s3';
    case Pkcs7S4 = 'adbe.pkcs7.s4';
    case Pkcs7S5 = 'adbe.pkcs7.s5';
}
