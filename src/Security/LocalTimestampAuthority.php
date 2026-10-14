<?php

declare(strict_types=1);

namespace PDF\Security;

use PDF\Document\Signatures\TimestampRequest;
use PDF\Document\Signatures\TimestampResponse;
use PDF\Document\Signatures\TimestampSerialSource;
use PDF\Document\Signatures\TimestampToken;
use PDF\Document\Signatures\TimestampTokenProvider;

/** Explicit offline RFC 3161 authority backend. It makes no automatic network or trust decisions. */
final readonly class LocalTimestampAuthority implements TimestampTokenProvider
{
    private OpenSslCommand $command;
    private OpenSslTimestampTokenVerifier $verifier;

    public function __construct(string $executable, private CertificateChain $authority, #[\SensitiveParameter] private string $privateKeyPem, private TimestampSerialSource $serials, private string $policy, private ?string $temporaryDirectory = null, private \PDF\Document\Signatures\Policies\SignatureDigestMethod $signingDigest = \PDF\Document\Signatures\Policies\SignatureDigestMethod::SHA256)
    {
        $this->command = new OpenSslCommand($executable);
        $this->verifier = new OpenSslTimestampTokenVerifier($authority, $temporaryDirectory);
        new OpenSslIdentity($authority, $privateKeyPem);
        (new CmsDerEncoding())->oid($policy);
    }

    public function request(TimestampRequest $request): TimestampToken
    {
        if ($request->policy !== null && $request->policy !== $this->policy) {
            throw new \InvalidArgumentException('The local timestamp authority does not issue the requested policy.');
        }
        $files = new CmsTemporaryFiles($this->temporaryDirectory);
        try {
            $key = $this->configurationPath($files->write($this->privateKeyPem));
            $certificate = $this->configurationPath($files->write($this->authority->signer->pem()));
            $previousSerial = $this->previousSerial($this->serials->next());
            $serial = $this->configurationPath($files->write(strtoupper(bin2hex($previousSerial)) . "\n"));
            $configuration = "[tsa]\ndefault_tsa=authority\n[authority]\nserial=\"$serial\"\ncrypto_device=builtin\nsigner_cert=\"$certificate\"\nsigner_key=\"$key\"\nsigner_digest=" . $this->signingDigest->hashName() . "\ndefault_policy=" . $this->policy . "\ndigests=" . $request->digest->hashName() . "\nordering=no\ntsa_name=yes\ness_cert_id_chain=no\ness_cert_id_alg=sha256\n";
            $config = $files->write($configuration);
            $query = $files->write($request->der());
            $response = $files->write();
            $this->command->run(['ts', '-reply', '-config', $config, '-section', 'authority', '-queryfile', $query, '-out', $response]);
            $token = (new TimestampResponse($files->read($response)))->token;
            $this->verifier->verify($token, $request);
            return $token;
        } finally {
            $files->close();
        }
    }

    /** @return array{authority:CertificateChain} */
    public function __debugInfo(): array
    {
        return ['authority' => $this->authority];
    }

    private function configurationPath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if (preg_match('/[\r\n"$]/', $path)) {
            throw new \InvalidArgumentException('Timestamp staging paths cannot contain OpenSSL configuration syntax.');
        }
        return $path;
    }

    private function previousSerial(string $serial): string
    {
        if ($serial === '' || strlen($serial) > 20 || $serial[0] === "\0") {
            throw new \InvalidArgumentException('The serial source supplies a positive minimally encoded value of at most 160 bits.');
        }
        for ($index = strlen($serial) - 1; $index >= 0; --$index) {
            $value = ord($serial[$index]);
            $serial[$index] = chr(($value - 1) & 255);
            if ($value !== 0) {
                break;
            }
        }
        return ltrim($serial, "\0") ?: "\0";
    }
}
