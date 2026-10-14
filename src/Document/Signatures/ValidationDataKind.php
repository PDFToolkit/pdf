<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

enum ValidationDataKind: string
{
    case Certificate = 'Certs';
    case CertificateRevocationList = 'CRLs';
    case OnlineCertificateStatus = 'OCSPs';

    public function vriKey(): string
    {
        return match ($this) {
            self::Certificate => 'Cert', self::CertificateRevocationList => 'CRL', self::OnlineCertificateStatus => 'OCSP',
        };
    }
}
