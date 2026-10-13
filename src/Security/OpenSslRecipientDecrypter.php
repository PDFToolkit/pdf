<?php

declare(strict_types=1);

namespace PDF\Security;

/** Native private-key access is isolated from PDF construction and explicit per recipient operation. */
final readonly class OpenSslRecipientDecrypter implements RecipientDecrypter
{
    public function __construct(private OpenSslIdentity $identity, private ?string $temporaryDirectory = null)
    {
    }

    public function decrypt(PublicKeyRecipient $recipient): ?string
    {
        $files = new CmsTemporaryFiles($this->temporaryDirectory);
        try {
            $input = $files->write($recipient->value->value);
            $output = $files->write();
            $success = openssl_cms_decrypt($input, $output, $this->identity->certificate, $this->identity->key(), OPENSSL_ENCODING_DER);
            return $success ? $files->read($output) : null;
        } finally {
            $files->close();
        }
    }
}
