<?php

declare(strict_types=1);

namespace PDF\Security;

use PDF\Document\Signatures\Policies\SignatureDigestMethod;

/** Explicit OpenSSL executable provider for EdDSA, unavailable through PHP 8.3 openssl_sign. */
final readonly class OpenSslEdDsaSignaturePrimitive implements ModernSignaturePrimitive
{
    private string $executable;

    public function __construct(string $executable, #[\SensitiveParameter] private string $privateKeyPem, private ?string $temporaryDirectory = null)
    {
        $resolved = realpath($executable);
        if ($resolved === false || !is_file($resolved) || str_contains($resolved, "\0")
            || !str_starts_with($privateKeyPem, '-----BEGIN PRIVATE KEY-----') || openssl_pkey_get_private($privateKeyPem) === false) {
            throw new \InvalidArgumentException('The EdDSA provider requires an explicit existing local OpenSSL executable.');
        }
        $this->executable = $resolved;
    }

    public function sign(string $bytes, OpenSslIdentity $identity, SignatureDigestMethod $digest): string
    {
        $curve = (new NamedCurveCertificate($identity->chain->signer))->curve;
        $curve->validate($digest);
        if ($curve->keyAlgorithm() !== \PDF\Document\Signatures\Policies\SignatureKeyAlgorithm::EdDSA
            || !openssl_x509_check_private_key($identity->certificate, $this->privateKeyPem)) {
            throw new \InvalidArgumentException('The selected EdDSA primitive must bind the actual EdDSA signing credential.');
        }
        $files = new CmsTemporaryFiles($this->temporaryDirectory);
        try {
            $key = $files->write($this->privateKeyPem);
            $input = $files->write($bytes);
            $output = $files->write();
            $this->run(['pkeyutl', '-sign', '-rawin', '-inkey', $key, '-in', $input, '-out', $output]);
            return $files->read($output);
        } finally {
            $files->close();
        }
    }

    /** @return array{provider:string} */
    public function __debugInfo(): array
    {
        return ['provider' => $this->executable];
    }

    public function verify(string $bytes, string $signature, X509Certificate $certificate, SignatureDigestMethod $digest): bool
    {
        (new NamedCurveCertificate($certificate))->curve->validate($digest);
        $files = new CmsTemporaryFiles($this->temporaryDirectory);
        try {
            $cert = $files->write($certificate->pem());
            $input = $files->write($bytes);
            $raw = $files->write($signature);
            return $this->run(['pkeyutl', '-verify', '-rawin', '-certin', '-inkey', $cert, '-in', $input, '-sigfile', $raw], false) === 0;
        } finally {
            $files->close();
        }
    }

    /** @param list<string> $arguments */
    private function run(array $arguments, bool $throw = true): int
    {
        $process = proc_open([$this->executable, ...$arguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new \RuntimeException('Cannot start the explicitly selected OpenSSL provider.');
        }
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($throw && $status !== 0) {
            throw new \RuntimeException('The explicitly selected OpenSSL EdDSA provider failed.');
        }
        return $status;
    }
}
