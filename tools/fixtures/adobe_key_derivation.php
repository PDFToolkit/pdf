<?php

declare(strict_types=1);

use PDF\Security\EncryptMetadata;
use PDF\Security\FileIdentifier;
use PDF\Security\HistoricalKeyLength;
use PDF\Security\HistoricalOwnerPasswordEntry;
use PDF\Security\HistoricalPublicKey256KeyDerivation;
use PDF\Security\HistoricalSecurityRevision;
use PDF\Security\HistoricalStandardKeyDerivation;
use PDF\Security\PdfDocEncodedPassword;
use PDF\Security\PublicKeyRecipient;
use PDF\Security\PublicKeySeed;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\StringObject\HexadecimalString;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$data = json_decode((string) file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
foreach ($data['legacy'] as $vector) {
    $key = (new HistoricalStandardKeyDerivation())->derive(HistoricalSecurityRevision::from($vector['R']),
        new PdfDocEncodedPassword(new HexadecimalString(hex2bin($vector['userPasswordHex']))),
        new HistoricalOwnerPasswordEntry(new HexadecimalString(hex2bin($vector['O']))), new IntegerType($vector['P']),
        new FileIdentifier(new HexadecimalString(hex2bin($vector['ID']))), new HistoricalKeyLength(new IntegerType($vector['Length'])), new EncryptMetadata($vector['EncryptMetadata']));
    echo bin2hex($key->bytes()) . "\n";
}
foreach ($data['publicKey'] as $vector) {
    $recipients = array_map(static fn (string $bytes): PublicKeyRecipient => new PublicKeyRecipient(new HexadecimalString(hex2bin($bytes))), $vector['recipients']);
    $key = (new HistoricalPublicKey256KeyDerivation())->derive(new PublicKeySeed(new HexadecimalString(hex2bin($vector['seed']))), new EncryptMetadata($vector['EncryptMetadata']), ...$recipients);
    echo bin2hex($key->bytes()) . "\n";
}
