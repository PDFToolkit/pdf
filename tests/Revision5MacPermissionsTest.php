<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Security\EncryptMetadata;
use PDF\Security\HistoricalRevision5SecurityHandler;
use PDF\Security\OpenSslAes256Backend;
use PDF\Security\PreparedPassword;
use PDF\Security\Revision5EncryptionDictionary;
use PDF\Security\Revision5Permissions;
use PDF\Security\SystemRandomSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** ISO-TS-32004-2024_sponsored.pdf Table3, physical p11; historical R5 keeps its original other bits. */
final class Revision5MacPermissionsTest extends TestCase
{
    public function testMacPolicyPreservesTheExactSignedPBytes(): void
    {
        $legacy = new Revision5Permissions(-4);
        self::assertFalse($legacy->requireMac);
        self::assertSame('fcffffffffffffff', bin2hex($legacy->bytes()));
        $mac = new Revision5Permissions(-4100, requireMac: true);
        self::assertTrue($mac->requireMac);
        self::assertSame(-4100, $mac->value);
        self::assertSame('fcefffffffffffff', bin2hex($mac->bytes()));
    }

    public function testHistoricalAccessibilityFlagIsNotReplacedWithPdf20Policy(): void
    {
        $withoutAccessibility = new Revision5Permissions(-516);
        self::assertSame(0, $withoutAccessibility->value & 0x200);
        $withMac = new Revision5Permissions(-4612, requireMac: true);
        self::assertSame(0, $withMac->value & 0x200);
        self::assertSame('fcedffffffffffff', bin2hex($withMac->bytes()));
    }

    /** @return iterable<string,array{int,bool}> */
    public static function invalid(): iterable
    {
        yield 'MAC flag requires bit13 zero' => [-4, true];
        yield 'bit13 zero requires explicit MAC policy' => [-4100, false];
        yield 'reserved bit1' => [-4099, true];
        yield 'reserved bit2' => [-4098, true];
        yield 'positive' => [4, true];
        yield 'underflow' => [-2147483649, true];
        foreach ([7, 8, 14, 15, 16, 31, 32] as $bit) {
            yield 'other reserved bit' . $bit => [-4100 ^ (1 << ($bit - 1)), true];
        }
    }

    #[DataProvider('invalid')]
    public function testMacPolicyDoesNotRelaxOtherReservedBits(int $value, bool $requireMac): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Revision5Permissions($value, $requireMac);
    }

    /** @return iterable<string,array{int}> */
    public static function historicalOptionalBits(): iterable
    {
        foreach ([3, 4, 5, 6, 9, 10, 11, 12] as $bit) {
            yield 'bit' . $bit => [$bit];
        }
    }

    #[DataProvider('historicalOptionalBits')]
    public function testMacPolicyRetainsEveryHistoricalUserPermission(int $bit): void
    {
        $value = -4100 ^ (1 << ($bit - 1));
        $permissions = new Revision5Permissions($value, requireMac: true);
        self::assertSame(0, $permissions->value & (1 << ($bit - 1)));
        self::assertSame(pack('V', $value) . "\xff\xff\xff\xff", $permissions->bytes());
    }

    public function testHistoricalPermissionAuthenticationStillBindsTheMacBitInEncryptedPerms(): void
    {
        $backend = new OpenSslAes256Backend();
        $handler = new HistoricalRevision5SecurityHandler($backend, new SystemRandomSource());
        $permissions = new Revision5Permissions(-4100, requireMac: true);
        $encryption = $handler->create(new PreparedPassword('user'), new PreparedPassword('owner'), $permissions, new EncryptMetadata(false));
        $plain = $backend->decryptEcb($encryption->dictionary->permissionsBlock, $encryption->key);
        self::assertSame($permissions->bytes() . 'Fadb', substr($plain, 0, 12));
        self::assertNotNull($handler->authenticate(new PreparedPassword('user'), $encryption->dictionary));
        self::assertNull($handler->authenticate(new PreparedPassword('wrong'), $encryption->dictionary));
        $dictionary = $encryption->dictionary;
        $strippedMacPolicy = new Revision5EncryptionDictionary($dictionary->owner, $dictionary->user, $dictionary->ownerEncryption, $dictionary->userEncryption, $dictionary->permissionsBlock, new Revision5Permissions(-4), $dictionary->encryptMetadata);
        $this->expectException(\UnexpectedValueException::class);
        $handler->authenticate(new PreparedPassword('user'), $strippedMacPolicy);
    }
}
