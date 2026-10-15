<?php

declare(strict_types=1);

namespace PDF\Tests\Support;

use PDF\Security as Sec;
use PDF\Document\Signatures as Sig;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringObject\HexadecimalString;

/** Public, reproducible test identities; no production credentials or trust store. */
final readonly class UsageRightsEncryptionCredentials
{
    public Sec\FileEncryption $encryption;
    public Sig\PasswordUsageRightsAuthentication|Sig\CertificateUsageRightsAuthentication $authentication;
    public string $version;

    public function __construct(string $kind, bool $fill = true, string $credential = 'user', bool $allAccess = false)
    {
        $word = -4 & ~8 & ~32 & ~256 & ~1024;
        if ($fill) {
            $word |= $kind === 'r2' || $kind === 's3' ? 32 : 256;
        }
        $this->version = in_array($kind, ['r6', 'r7', 's5gcm'], true) ? '2.0' : '1.7';
        $random = new Sec\SystemRandomSource();
        if (str_starts_with($kind, 'r')) {
            if (in_array($kind, ['r2', 'r3', 'r4', 'r4aes'], true)) {
                $handler = new Sec\HistoricalStandardSecurityHandler(aes: new Sec\OpenSslAes128Backend());
                $revision = $kind === 'r2' ? Sec\HistoricalSecurityRevision::Revision2 : ($kind === 'r3' ? Sec\HistoricalSecurityRevision::Revision3 : Sec\HistoricalSecurityRevision::Revision4);
                if ($kind === 'r2') {
                    $word |= -64;
                }
                $this->encryption = $handler->create($revision, $this->password('user'), $this->password('owner'), new Sec\HistoricalPermissions(new IntegerType($word), $revision), new Sec\FileIdentifier(new HexadecimalString(str_repeat('u', 16))), new Sec\HistoricalKeyLength(new IntegerType($kind === 'r2' ? 40 : 128)), $kind === 'r4aes' ? Sec\HistoricalDataCipher::Aes128 : Sec\HistoricalDataCipher::Rc4);
                $this->authentication = new Sig\PasswordUsageRightsAuthentication($this->password($credential), $handler);
            } else {
                if ($kind === 'r5') {
                    $handler = new Sec\HistoricalRevision5SecurityHandler(new Sec\OpenSslAes256Backend(), $random);
                    $this->encryption = $handler->create(new Sec\PreparedPassword('user'), new Sec\PreparedPassword('owner'), new Sec\Revision5Permissions($word), new Sec\EncryptMetadata(true));
                } else {
                    $handler = new Sec\ModernStandardSecurityHandler(new Sec\OpenSslAes256Backend(), $random);
                    $this->encryption = $handler->create(new Sec\PreparedPassword('user'), new Sec\PreparedPassword('owner'), new Sec\Pdf20Permissions($word), new Sec\EncryptMetadata(true), $kind === 'r7' ? Sec\ModernSecurityRevision::Aes256Gcm : Sec\ModernSecurityRevision::Aes256Cbc);
                }
                $this->authentication = new Sig\PasswordUsageRightsAuthentication(new Sec\PreparedPassword($credential), $handler);
            }
            return;
        }
        $word = $allAccess ? $word | 2 : $word;
        if ($kind === 's3' && !$allAccess) {
            $word |= -64; // Reserved upper bits must not become R3 permissions.
        }
        $handler = new Sec\PublicKeySecurityHandler(new Sec\OpenSslCmsEnvelopeBackend(), $this->version === '2.0' ? Sec\PublicKeyPermissionByteOrder::MostSignificantFirst : Sec\PublicKeyPermissionByteOrder::IsoLeastSignificantFirst, aes128: new Sec\OpenSslAes128Backend(), aes256: new Sec\OpenSslAes256Backend());
        $group = new Sec\PublicKeyRecipientGroup(new Sec\PublicKeyPermissions(new IntegerType($word)), $this->certificate('signer'));
        $compatibility = new Sec\PublicKeyPermissions(new IntegerType(-4));
        if ($kind === 's3' || $kind === 's4') {
            $this->encryption = $handler->createLegacy($kind === 's3' ? Sec\PublicKeySubFilter::Pkcs7S3 : Sec\PublicKeySubFilter::Pkcs7S4, new Sec\PublicKeyKeyLength(new IntegerType($kind === 's3' ? 40 : 128)), $compatibility, $group);
        } else {
            $cipher = match ($kind) {
                's5rc4' => Sec\PublicKeyCipher::Rc4,
                's5aes' => Sec\PublicKeyCipher::Aes128,
                's5aes256' => Sec\PublicKeyCipher::Aes256,
                's5gcm' => Sec\PublicKeyCipher::Aes256Gcm,
            };
            $name = new NameType('DefaultCryptFilter');
            $filter = $handler->createFilter($name, $cipher, new Sec\PublicKeyKeyLength(new IntegerType(in_array($kind, ['s5aes256', 's5gcm'], true) ? 256 : 128)), new Sec\EncryptMetadata(true), Sec\PublicKeyRecipientScope::Document, new NameType('DocOpen'), $group);
            $this->encryption = new Sec\PublicKeyEncryption(new Sec\PublicKeyFilterConfiguration($name, $name, $name, $filter), $compatibility);
        }
        $this->authentication = new Sig\CertificateUsageRightsAuthentication($this->decrypter($credential === 'bad' ? 'other-recipient' : 'signer'));
    }

    public function certificate(string $name): Sec\X509Certificate
    {
        return new Sec\X509Certificate(new HexadecimalString(file_get_contents(dirname(__DIR__) . '/Fixtures/signing/' . $name . '.der')));
    }

    public function decrypter(string $name): Sec\OpenSslRecipientDecrypter
    {
        return new Sec\OpenSslRecipientDecrypter(new Sec\OpenSslIdentity(new Sec\CertificateChain($this->certificate($name)), file_get_contents(dirname(__DIR__) . '/Fixtures/signing/' . $name . '-private-key.pem')));
    }

    private function password(string $value): Sec\PdfDocEncodedPassword
    {
        return new Sec\PdfDocEncodedPassword(new HexadecimalString($value));
    }
}
