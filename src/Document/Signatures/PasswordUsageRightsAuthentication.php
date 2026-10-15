<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

use PDF\Security\FileEncryption;
use PDF\Security\HistoricalRevision5Encryption;
use PDF\Security\HistoricalRevision5SecurityHandler;
use PDF\Security\HistoricalSecurityRevision;
use PDF\Security\HistoricalStandardEncryption;
use PDF\Security\HistoricalStandardSecurityHandler;
use PDF\Security\ModernFileEncryption;
use PDF\Security\ModernStandardSecurityHandler;
use PDF\Security\PasswordAccess;
use PDF\Security\PdfDocEncodedPassword;
use PDF\Security\PreparedPassword;

/** Per-update password authentication; caller-supplied AuthenticatedFileKey values are never authority. */
final readonly class PasswordUsageRightsAuthentication
{
    public function __construct(
        private PreparedPassword|PdfDocEncodedPassword $password,
        private ModernStandardSecurityHandler|HistoricalRevision5SecurityHandler|HistoricalStandardSecurityHandler $handler,
    ) {
        if (($password instanceof PdfDocEncodedPassword) !== ($handler instanceof HistoricalStandardSecurityHandler)) {
            throw new \InvalidArgumentException('The password encoding must match the explicitly selected security handler.');
        }
    }

    public function authenticate(FileEncryption $encryption): UsageRightsSecurityPermissions
    {
        $revision2 = false;
        try {
            if ($encryption instanceof HistoricalStandardEncryption && $this->handler instanceof HistoricalStandardSecurityHandler && $this->password instanceof PdfDocEncodedPassword) {
                $result = $this->handler->authenticate($this->password, $encryption->dictionary, $encryption->identifier);
                $word = $encryption->dictionary->permissions->value->integer();
                $revision2 = $encryption->dictionary->revision === HistoricalSecurityRevision::Revision2;
            } elseif ($encryption instanceof HistoricalRevision5Encryption && $this->handler instanceof HistoricalRevision5SecurityHandler && $this->password instanceof PreparedPassword) {
                $result = $this->handler->authenticate($this->password, $encryption->dictionary);
                $word = $encryption->dictionary->permissions->value;
            } elseif ($encryption instanceof ModernFileEncryption && $this->handler instanceof ModernStandardSecurityHandler && $this->password instanceof PreparedPassword) {
                $result = $this->handler->authenticate($this->password, $encryption->credentials);
                $word = $encryption->credentials->permissions->value;
            } else {
                throw new \LogicException('Mismatched password security handler.');
            }
            if ($result === null || !hash_equals($encryption->key->bytes(), $result->key->bytes())) {
                throw new \LogicException('The credential did not recover the actual file key.');
            }
        } catch (\Throwable) {
            throw new \LogicException('Usage-rights password authentication failed for the actual encrypted source.');
        }
        return new UsageRightsSecurityPermissions($word, $revision2, $result->access === PasswordAccess::Owner);
    }
}
