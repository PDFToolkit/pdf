<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\Artifacts\BatesArtifact;
use PDF\Document\Extensions\DeveloperExtension;
use PDF\Document\Extensions\DeveloperExtensionEntry;
use PDF\Document\Extensions\DeveloperExtensions;
use PDF\Document\Extensions\DeveloperPrefix;
use PDF\Document\RichMedia\WindowDimension;
use PDF\Serialization\SerializationContext;
use PDF\Specification\ObjectTypeConstraint;
use PDF\Specification\SourceReference;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StringObject\LiteralString;
use PHPUnit\Framework\TestCase;

final class AdobeExtensionObjectsTest extends TestCase
{
    public function testDeclarationsRetainCaseAndIndependentBaseVersions(): void
    {
        $extensions = new DeveloperExtensions(
            new DeveloperExtensionEntry(new DeveloperPrefix(new NameType('ADBE')), new DeveloperExtension(new NameType('1.7'), new IntegerType(3))),
            new DeveloperExtensionEntry(new DeveloperPrefix(new NameType('adbe')), new DeveloperExtension(new NameType('500'), new IntegerType(1002))),
        );
        $pdf = $extensions->toPdf(new SerializationContext());
        self::assertStringContainsString('/Type /Extensions', $pdf);
        self::assertStringContainsString('/ADBE << /BaseVersion /1.7 /ExtensionLevel 3 >>', $pdf);
        self::assertStringContainsString('/adbe << /BaseVersion /500 /ExtensionLevel 1002 >>', $pdf);
    }

    public function testDuplicatePrefixIsRejected(): void
    {
        $entry = new DeveloperExtensionEntry(new DeveloperPrefix(new NameType('ADBE')), new DeveloperExtension(new NameType('1.7'), new IntegerType(3)));
        $this->expectException(\InvalidArgumentException::class);
        new DeveloperExtensions($entry, $entry);
    }

    public function testPrefixCannotUseReservedTypeKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DeveloperPrefix(new NameType('Type'));
    }

    public function testPrefixRequiresFourBytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DeveloperPrefix(new NameType('Adobe'));
    }

    public function testDeveloperDeclarationsRemainDirect(): void
    {
        $target = new DeveloperExtension(new NameType('1.7'), new IntegerType(3));
        $extensions = new DeveloperExtensions(new DeveloperExtensionEntry(new DeveloperPrefix(new NameType('ADBE')), $target));
        self::assertSame($target, $extensions->get('ADBE'));
        self::assertStringContainsString('/ADBE <<', $extensions->toPdf(new SerializationContext()));
    }

    public function testReferenceToAnotherObjectFamilyIsRejected(): void
    {
        $this->expectException(\TypeError::class);
        new DeveloperExtensionEntry(new DeveloperPrefix(new NameType('ADBE')), new IndirectReference(new ArrayType()));
    }

    public function testEmptyExtensionsAreRejectedAtSerialization(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new DeveloperExtensions())->toPdf(new SerializationContext());
    }

    public function testDeveloperGenericUpdatesRejectNestedIndirectObjects(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new DeveloperExtension(new NameType('1.7'), new IntegerType(3)))->with(
            new Entry(new NameType('Custom'), new ArrayType(new IndirectReference(new LiteralString('indirect')))),
        );
    }

    public function testGenericDeveloperReferencesAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new DeveloperExtensions())->with(new Entry(new NameType('ADBE'), new IndirectReference(new DeveloperExtension(new NameType('1.7'), new IntegerType(3)))));
    }

    public function testGenericEntriesCannotBypassDeveloperRequirements(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new DeveloperExtensions())->with(new Entry(new NameType('ADBE'), new DictionaryType(new Entry(new NameType('BaseVersion'), new NameType('1.7')))));
    }

    public function testGenericEntriesCannotBypassDeveloperTypes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new DeveloperExtensions())->with(new Entry(new NameType('ADBE'), new DictionaryType(
            new Entry(new NameType('BaseVersion'), new NameType('1.7')),
            new Entry(new NameType('ExtensionLevel'), new NumericType(3.5)),
        )));
    }

    public function testDeclarationUpdatesLeaveTheSourceUnchanged(): void
    {
        $extension = new DeveloperExtension(new NameType('1.7'), new IntegerType(3));
        $updated = $extension->with(new Entry(new NameType('ExtensionLevel'), new IntegerType(4)));
        self::assertSame(3, $extension->get('ExtensionLevel')?->value);
        self::assertSame(4, $updated->get('ExtensionLevel')?->value);
        $this->expectException(\InvalidArgumentException::class);
        $extension->without('BaseVersion');
    }

    public function testBatesArtifactRetainsSingleAndMultipleNumbers(): void
    {
        $single = new BatesArtifact(new LiteralString('DOC-000001'));
        self::assertSame('Pagination', $single->get('Type')?->value);
        self::assertSame('BatesN', $single->get('Subtype')?->value);
        self::assertStringContainsString('/Contents (DOC-000001)', $single->toPdf(new SerializationContext()));
        $numbers = new ArrayType(new LiteralString('DOC-000001'), new LiteralString('EXH-000019'));
        $multiple = new BatesArtifact($numbers);
        self::assertSame($numbers, $multiple->get('Contents'));
        self::assertSame('adobe_supplement_iso32000.pdf', $multiple->definition()->source->document);
        self::assertSame(100, $multiple->definition()->source->pdfPage);
    }

    public function testBatesContentsAreOptionalAndCannotContainNumbers(): void
    {
        self::assertFalse((new BatesArtifact())->has('Contents'));
        $this->expectException(\InvalidArgumentException::class);
        new BatesArtifact(new ArrayType(new LiteralString('DOC-000001'), new IntegerType(1)));
    }

    public function testBatesGenericUpdatesCannotChangeItsSubtype(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $invalid = (new BatesArtifact())->with(new Entry(new NameType('Subtype'), new NameType('Header')));
    }

    public function testBatesGenericUpdatesCannotBypassArrayMemberTypes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $invalid = (new BatesArtifact())->with(new Entry(new NameType('Contents'), new ArrayType(new DictionaryType())));
    }

    public function testUnionAllowsTextOrReferencedTextArrayMembers(): void
    {
        $constraint = new ObjectTypeConstraint('text string or array of text string');
        $constraint->validate(new LiteralString('DOC-000001'));
        $constraint->validate(new ArrayType(new IndirectReference(new LiteralString('DOC-000001'))));
        $this->expectException(\InvalidArgumentException::class);
        $constraint->validate(new ArrayType(new IndirectReference(new IntegerType(1))));
    }

    public function testArrayAlternativesMustBeHomogeneous(): void
    {
        $constraint = new ObjectTypeConstraint('array of names or array of numbers');
        $constraint->validate(new ArrayType(new NameType('A')));
        $constraint->validate(new ArrayType(new NumericType(1.5)));
        $this->expectException(\InvalidArgumentException::class);
        $constraint->validate(new ArrayType(new NameType('A'), new NumericType(1.5)));
    }

    public function testIndirectArrayElementsAreDistinctFromAnIndirectArray(): void
    {
        $constraint = new ObjectTypeConstraint('array of dictionaries', indirectArrayElements: true);
        $constraint->validate(new ArrayType(new IndirectReference(new DictionaryType())));
        $this->expectException(\InvalidArgumentException::class);
        $constraint->validate(new IndirectReference(new ArrayType(new DictionaryType())));
    }

    public function testContradictoryArrayIndirectionRequirementsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ObjectTypeConstraint('array', directArrayElements: true, indirectArrayElements: true);
    }

    public function testMixedCommandArgumentsUseAnExplicitMemberConstraint(): void
    {
        $constraint = new ObjectTypeConstraint(
            'text string or integer or real or boolean or array',
            arrayElementConstraint: new ObjectTypeConstraint('text string or integer or real or boolean'),
        );
        $constraint->validate(new ArrayType(new LiteralString('argument'), new IntegerType(5), new NumericType(2.5), new \PDF\Syntax\Object\BooleanType(true)));
        $this->expectException(\InvalidArgumentException::class);
        $constraint->validate(new ArrayType(new LiteralString('argument'), new ArrayType(new IntegerType(5))));
    }

    public function testAnArrayFirstUnionRetainsItsScalarAlternative(): void
    {
        $constraint = new ObjectTypeConstraint('array of names or text string');
        $constraint->validate(new LiteralString('argument'));
        $constraint->validate(new ArrayType(new NameType('A')));
        $this->expectException(\InvalidArgumentException::class);
        $constraint->validate(new NumericType(1));
    }

    public function testDimensionBoundsAreCheckedOnImmutableUpdates(): void
    {
        $width = new WindowDimension(new NumericType(288), new NumericType(72), new NumericType(576));
        $updated = $width->with(new Entry(new NameType('Default'), new NumericType(300)));
        self::assertSame(288, $width->get('Default')?->value);
        self::assertSame(300, $updated->get('Default')?->value);
        $this->expectException(\InvalidArgumentException::class);
        $width->with(new Entry(new NameType('Max'), new NumericType(200)));
    }

    public function testSourceDocumentDefaultsRemainCompatibleAndTheNewSourceMatchesItsFingerprint(): void
    {
        self::assertSame('PDF32000_2008.pdf', (new SourceReference('50', '7.12', 116))->document);
        $source = (new BatesArtifact())->definition()->source;
        self::assertSame($source->documentSha256, hash_file('sha256', dirname(__DIR__) . '/resources/reference-documents/' . $source->document));
    }
}
