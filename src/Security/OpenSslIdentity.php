<?php

declare(strict_types=1);

namespace PDF\Security;

/** Explicit native backend credentials. PEM bytes are inputs, never filenames or automatic file reads. */
final readonly class OpenSslIdentity
{
    private \OpenSSLAsymmetricKey $privateKey;
    public \OpenSSLCertificate $certificate;

    public function __construct(
        public CertificateChain $chain,
        #[\SensitiveParameter] string $privateKeyPem,
        #[\SensitiveParameter] string $passphrase = ''
    ) {
        if (!extension_loaded('openssl')) {
            throw new \LogicException('The explicitly selected OpenSSL backend requires ext-openssl.');
        }
        if (!preg_match('/\A-----BEGIN (?:RSA |EC |ENCRYPTED )?PRIVATE KEY-----/', $privateKeyPem)) {
            throw new \InvalidArgumentException('Supply private-key PEM bytes, not a path or URI.');
        }
        $certificate = openssl_x509_read($chain->signer->pem());
        $key = openssl_pkey_get_private($privateKeyPem, $passphrase);
        if ($certificate === false || $key === false || !openssl_x509_check_private_key($certificate, $key)) {
            throw new \InvalidArgumentException('The private key and signer certificate must form a valid matching pair.');
        }
        $this->certificate = $certificate;
        $this->privateKey = $key;
    }

    public function key(): \OpenSSLAsymmetricKey
    {
        return $this->privateKey;
    }

    /** @return array{certificate: X509Certificate} */
    public function __debugInfo(): array
    {
        return ['certificate' => $this->chain->signer];
    }
}
