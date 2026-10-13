<?php

declare(strict_types=1);

namespace PDF\Security;

use PDF\Syntax\Object\IntegerType;

/**
 * The historical MD5 key primitive only: supplement Algorithm 3.2, pages 18–19.
 * This neither authenticates a password nor enables an R=2/3/4 encrypted writer.
 * Password bytes must already be PDFDocEncoding, distinct from R=5 SASLprep input.
 */
final readonly class HistoricalStandardKeyDerivation
{
    public function derive(
        HistoricalSecurityRevision $revision,
        PdfDocEncodedPassword $password,
        HistoricalOwnerPasswordEntry $owner,
        IntegerType $permissions,
        FileIdentifier $identifier,
        HistoricalKeyLength $length,
        EncryptMetadata $metadata,
    ): HistoricalEncryptionKey {
        $flags = $permissions->integer();
        if ($flags < -2147483648 || $flags > 2147483647) {
            throw new \InvalidArgumentException('The P entry must fit a signed 32-bit integer.');
        }
        $input = $password->paddedBytes() . $owner->value->value . pack('V', $flags) . $identifier->value->value;
        if ($revision === HistoricalSecurityRevision::Revision4 && !$metadata->value) {
            $input .= "\xff\xff\xff\xff";
        }
        $digest = hash('md5', $input, true);
        $bytes = $revision === HistoricalSecurityRevision::Revision2 ? 5 : $length->bytes();
        if ($revision !== HistoricalSecurityRevision::Revision2) {
            for ($iteration = 0; $iteration < 50; ++$iteration) {
                $digest = hash('md5', substr($digest, 0, $bytes), true);
            }
        }
        return new HistoricalEncryptionKey(substr($digest, 0, $bytes));
    }
}
