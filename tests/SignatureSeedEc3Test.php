<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\Signatures\Policies as Policy;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\RealType;
use PDF\Syntax\FileStructure\CrossReferenceFormat;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Tests\Support\SignatureSeedEc3Fixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SignatureSeedEc3Test extends TestCase
{
    public static function signatures(): iterable
    {
        foreach (['Ed25519', 'Ed448'] as $name) {
            foreach (CrossReferenceFormat::cases() as $format) {
                yield $name . $format->name => [$name, $format];
            }
        }
    }

    #[DataProvider('signatures')]
    public function testActualEdDsaIgnoresTheSeedDigestListAndRetainsIntegerV(string $name, CrossReferenceFormat $format): void
    {
        $fixture = new SignatureSeedEc3Fixture($name, $format);
        self::assertSame($name === 'Ed25519' ? Policy\SignatureDigestMethod::SHA512 : Policy\SignatureDigestMethod::SHAKE256, $fixture->signed->revision->verifiedSignature->facts->algorithms->digest);
        $graph = new \PDF\Document\Signatures\FrozenSignatureGraph($fixture->signed->revision);
        $field = $graph->bindings($fixture->signed->revision->binding($fixture->operation->signature)->identifier)[0];
        $seed = $graph->resolve($field->dictionary->get('SV'));
        self::assertInstanceOf(IntegerType::class, $seed->get('V'));
        self::assertSame('SHA256', $seed->get('DigestMethod')->items[0]->value);
        self::assertNull($graph->catalog->get('Extensions')->get('ADBE'));
    }

    public function testPdf20SemanticsCannotBeEmittedInPdf17(): void
    {
        $this->expectException(\LogicException::class);
        new SignatureSeedEc3Fixture('Ed25519', version: '1.7');
    }

    public function testPdf20VRejectsAnIntegralRealInsteadOfRewritingIt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Policy\SignatureSeedValue(entries: new Entry(new NameType('V'), new RealType(3)), semantics: Policy\SignatureSeedSemantics::Pdf20);
    }

    public function testHistoricalRealCannotBypassAnActualPdf20Version(): void
    {
        $seed = new Policy\SignatureSeedValue(entries: new Entry(new NameType('V'), new IntegerType(3)));
        self::assertInstanceOf(RealType::class, $seed->get('V'));
        $this->expectException(\InvalidArgumentException::class);
        $seed->validateVersion(new EffectivePdfVersion(new FileHeader('1.7'), new DictionaryType(new Entry(new NameType('Version'), new NameType('2.0')))));
    }

    public function testRsaWithOneBackendDigestStillHonoursItsMandatorySeed(): void
    {
        $backend = (new \PDF\Tests\Support\ProtectedRevisionFixture())->backend;
        $seed = new Policy\SignatureSeedValue(new Policy\SignatureSeedFlags(Policy\SignatureSeedFlag::DigestMethod), new Entry(new NameType('DigestMethod'), new ArrayType(new NameType('SHA512'))), Policy\SignatureSeedSemantics::Pdf20);
        $this->expectException(\LogicException::class);
        (new \PDF\Document\Signatures\SignatureDigestSelection())->select($seed, $backend->certificateFacts(), [Policy\SignatureDigestMethod::SHA256]);
    }
}
