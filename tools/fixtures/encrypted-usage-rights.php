<?php

declare(strict_types=1);

use PDF\Syntax\FileStructure\CrossReferenceFormat;
use PDF\Tests\Support\EncryptedUsageRightsFixture;
use PDF\Tests\Support\UsageRightsEncryptionCredentials;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
$output = $argv[1] ?? throw new InvalidArgumentException('Pass a fixture output directory.');
if (!is_dir($output) && !mkdir($output, 0777, true) && !is_dir($output)) {
    throw new RuntimeException('Cannot create fixture output directory.');
}
$cases = [];
$refusals = 0;
foreach (['r2', 'r3', 'r4', 'r4aes', 'r5', 'r6', 'r7', 's3', 's4', 's5rc4', 's5aes', 's5aes256', 's5gcm'] as $kind) {
    foreach (CrossReferenceFormat::cases() as $format) {
        foreach ([true, false] as $fill) {
            $credentials = new UsageRightsEncryptionCredentials($kind, $fill, $fill ? 'user' : 'owner', !$fill);
            $fixture = new EncryptedUsageRightsFixture($credentials->encryption, $credentials->version, $format);
            $stem = $kind . '-' . $format->name . '-' . ($fill ? 'fill' : 'owner');
            $fixture->revision->writeTo($output . '/' . $stem . '.pdf');
            $fixture->fill($credentials->authentication)->writeTo($output . '/' . $stem . '-filled.pdf');
            $wrong = new UsageRightsEncryptionCredentials($kind, $fill, 'bad');
            foreach ([null, $wrong->authentication] as $authentication) {
                try {
                    $fixture->fill($authentication);
                } catch (LogicException) {
                    ++$refusals;
                    continue;
                }
                throw new RuntimeException('Missing or wrong credentials must refuse the actual update.');
            }
            $encryption = $credentials->encryption;
            $word = $encryption instanceof \PDF\Security\HistoricalStandardEncryption ? $encryption->dictionary->permissions->value->integer()
                : ($encryption instanceof \PDF\Security\ModernFileEncryption ? $encryption->credentials->permissions->value
                : ($encryption instanceof \PDF\Security\HistoricalRevision5Encryption ? $encryption->dictionary->permissions->value
                : ($encryption instanceof \PDF\Security\LegacyPublicKeyEncryption ? $encryption->material->recipients[0]->permissions->unsigned
                : $encryption->filters->filters()[0]->material->recipients[0]->permissions->unsigned)));
            $cases[] = ['stem' => $stem, 'kind' => $kind, 'format' => $format->name, 'version' => $credentials->version, 'owner' => !$fill, 'permissionWord' => $word & 0xffffffff,
                'signerSha256' => hash('sha256', $fixture->revision->verifiedSignature->facts->certificate->certificate()->value)];
        }
    }
}
file_put_contents($output . '/manifest.json', json_encode(['cases' => $cases, 'refusals' => $refusals], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
