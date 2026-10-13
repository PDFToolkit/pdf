<?php

declare(strict_types=1);

namespace PDF\Security;

/** DER EnvelopedData, AES-128-CBC payload and RSA key transport through the optional native backend. */
final readonly class OpenSslCmsEnvelopeBackend implements CmsEnvelopeBackend
{
    public function __construct(private ?string $temporaryDirectory = null)
    {
        if (!function_exists('openssl_cms_encrypt')) {
            throw new \LogicException('The explicitly selected CMS backend requires ext-openssl.');
        }
    }

    public function envelope(#[\SensitiveParameter] string $payload, X509Certificate ...$certificates): PublicKeyRecipient
    {
        if ($certificates === []) {
            throw new \InvalidArgumentException('CMS needs at least one explicit certificate.');
        }
        $parsed = [];
        foreach ($certificates as $certificate) {
            $value = openssl_x509_read($certificate->pem());
            if ($value === false) {
                throw new \InvalidArgumentException('The native CMS backend requires valid X.509 DER certificates.');
            }
            $parsed[] = $value;
        }
        $files = new CmsTemporaryFiles($this->temporaryDirectory);
        try {
            $input = $files->write($payload);
            $output = $files->write();
            if (!openssl_cms_encrypt($input, $output, $parsed, null, OPENSSL_CMS_BINARY, OPENSSL_ENCODING_DER, OPENSSL_CIPHER_AES_128_CBC)) {
                throw new \RuntimeException('Native CMS recipient encryption failed.');
            }
            return new PublicKeyRecipient(new \PDF\Syntax\Object\StringObject\HexadecimalString($files->read($output)));
        } finally {
            $files->close();
        }
    }
}
