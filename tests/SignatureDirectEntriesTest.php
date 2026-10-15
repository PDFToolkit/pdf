<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\Signatures\PendingDocumentTimestamp;
use PDF\Document\Signatures\PendingSignature;
use PDF\Document\Signatures\SignatureCapacity;
use PDF\Document\Signatures\SignatureFormat;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SignatureDirectEntriesTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function associations(): iterable
    {
        foreach (['Metadata', 'AF'] as $key) {
            foreach ([false, true] as $timestamp) {
                yield $key . ($timestamp ? '-timestamp' : '-approval') => [$key, $timestamp];
            }
        }
    }

    #[DataProvider('associations')]
    public function testEvenDirectAssociationsAreForbiddenForByteRangeSignatures(string $key, bool $timestamp): void
    {
        $entry = new Entry(new NameType($key), $key === 'AF' ? new ArrayType(new DictionaryType()) : new DictionaryType());
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Metadata or AF');
        if ($timestamp) {
            new PendingDocumentTimestamp(new SignatureCapacity(8192), entries: $entry);
        } else {
            new PendingSignature(new SignatureCapacity(8192), SignatureFormat::Pkcs7Detached, entries: $entry);
        }
    }

    public function testAStreamCannotBecomeAnImplicitIndirectSignatureValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('direct objects');
        new PendingSignature(new SignatureCapacity(8192), SignatureFormat::Pkcs7Detached, entries: new Entry(new NameType('PrivateExtension'), new StreamObject('payload')));
    }
}
