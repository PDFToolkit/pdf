<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\Forms\AcroForm;
use PDF\Document\Forms\AppearanceState;
use PDF\Document\Forms\AppearanceStates;
use PDF\Document\Forms\FieldTree;
use PDF\Document\Forms\FieldTypeDeclaration;
use PDF\Document\Forms\FormAppearanceStream;
use PDF\Document\Forms\NonTerminalField;
use PDF\Document\Forms\PartialFieldName;
use PDF\Document\Forms\SignatureField;
use PDF\Document\Forms\WidgetAnnotation;
use PDF\Document\Forms\WidgetAppearance;
use PDF\Document\ResourceDictionary;
use PDF\Document\Signatures\FrozenSignatureGraph;
use PDF\Document\Signatures\PendingSignature;
use PDF\Document\Signatures\SignatureAppearanceSelection;
use PDF\Document\Signatures\SignatureCapacity;
use PDF\Document\Signatures\SignatureFormat;
use PDF\Document\Signatures\SignatureSigningIntent;
use PDF\FileStructure;
use PDF\Security\CertificateChain;
use PDF\Security\OpenSslIdentity;
use PDF\Security\OpenSslSignatureBackend;
use PDF\Security\X509Certificate;
use PDF\Serialization\SignatureWriter;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FrozenSignatureGraphTest extends TestCase
{
    private function entry(string $key, BasicType $value): Entry
    {
        return new Entry(new NameType($key), $value);
    }
    private function pending(): PendingSignature
    {
        return new PendingSignature(new SignatureCapacity(8192), SignatureFormat::Pkcs7Detached);
    }
    private function backend(): OpenSslSignatureBackend
    {
        return new OpenSslSignatureBackend(new OpenSslIdentity(new CertificateChain(new X509Certificate(new HexadecimalString(file_get_contents(__DIR__ . '/Fixtures/signing/signer.der')))), file_get_contents(__DIR__ . '/Fixtures/signing/signer-private-key.pem')));
    }
    private function raw(PendingSignature $signature, ArrayType $fields): void
    {
        $catalog = new DocumentCatalog(new PageTreeNode(), $this->entry('AcroForm', new DictionaryType($this->entry('Fields', $fields))));
        (new SignatureWriter())->prepare(new FileStructure(new FileHeader(), new FileBody($catalog)), $signature);
    }

    public function testTheActualSignatureFieldInheritsItsTypeAndQualifiedName(): void
    {
        $pending = $this->pending();
        $field = new SignatureField(new PartialFieldName(new TextString('approval')), value: new IndirectReference($pending), declaration: FieldTypeDeclaration::Inherited);
        $group = (new NonTerminalField(new PartialFieldName(new TextString('group')), $this->entry('FT', new NameType('Sig'))))->addField($field);
        $catalog = new DocumentCatalog(new PageTreeNode(), $this->entry('AcroForm', new IndirectReference(new AcroForm((new FieldTree())->addRoot($group)))));
        $prepared = (new SignatureWriter())->prepare(new FileStructure(new FileHeader(), new FileBody($catalog)), $pending);
        $actual = (new FrozenSignatureGraph($prepared->revision))->bindings($prepared->revision->binding($pending)->identifier)[0];
        self::assertSame('group.approval', $actual->qualifiedName);
        self::assertSame('Sig', $actual->effective('FT')->value);
        self::assertNotEmpty($prepared->signWithPolicy($this->backend())->value->bytes);
    }

    public function testAnUnnamedGroupDoesNotInventALeadingPeriodInTheQualifiedName(): void
    {
        $pending = $this->pending();
        $field = new SignatureField(new PartialFieldName(new TextString('approval')), value: new IndirectReference($pending));
        $group = (new NonTerminalField())->addField($field);
        $catalog = new DocumentCatalog(new PageTreeNode(), $this->entry('AcroForm', new IndirectReference(new AcroForm((new FieldTree())->addRoot($group)))));
        $prepared = (new SignatureWriter())->prepare(new FileStructure(new FileHeader(), new FileBody($catalog)), $pending);
        self::assertSame('approval', (new FrozenSignatureGraph($prepared->revision))->fields()[0]->qualifiedName);
    }

    public function testRepeatedActualFieldOwnershipIsRejectedEvenForRawDictionaries(): void
    {
        $pending = $this->pending();
        $field = new RawSignatureFieldFixture($pending);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cycles or repeated field ownership');
        $this->raw($pending, new ArrayType(new IndirectReference($field), new IndirectReference($field)));
    }

    public function testAnActualFieldCycleIsRejectedEvenForRawDictionaries(): void
    {
        $pending = $this->pending();
        $field = new RawSignatureFieldFixture($pending);
        $field->kids = new ArrayType(new IndirectReference($field));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cycles or repeated field ownership');
        $this->raw($pending, new ArrayType(new IndirectReference($field)));
    }

    public function testSeparateWidgetParentMustAgreeWithTheActualField(): void
    {
        $pending = $this->pending();
        $field = new RawSignatureFieldFixture($pending);
        $widget = new DictionaryType($this->entry('Subtype', new NameType('Widget')), $this->entry('Parent', new IndirectReference(new DictionaryType())));
        $field->kids = new ArrayType(new IndirectReference($widget));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('separate widget belongs once');
        $this->raw($pending, new ArrayType(new IndirectReference($field)));
    }

    public function testRepeatedSeparateWidgetOwnershipIsRejected(): void
    {
        $pending = $this->pending();
        $field = new RawSignatureFieldFixture($pending);
        $widget = new DictionaryType($this->entry('Subtype', new NameType('Widget')), $this->entry('Parent', new IndirectReference($field)));
        $field->kids = new ArrayType(new IndirectReference($widget), new IndirectReference($widget));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('separate widget belongs once');
        $this->raw($pending, new ArrayType(new IndirectReference($field)));
    }

    /** @return iterable<string, array{bool}> */
    public static function appearanceMatch(): iterable
    {
        yield 'selected actual state' => [true];
        yield 'unattached supplied appearance' => [false];
    }

    #[DataProvider('appearanceMatch')]
    public function testMergedWidgetAppearanceSelectionUsesTheActualNormalState(bool $matches): void
    {
        $pending = $this->pending();
        $box = new Rectangle(new NumericType(0), new NumericType(0), new NumericType(100), new NumericType(30));
        $selected = new FormAppearanceStream(new ContentStreams(), $box, new DictionaryType());
        $other = new FormAppearanceStream(new ContentStreams(), $box, new DictionaryType());
        $widget = new WidgetAnnotation($box, new WidgetAppearance(new AppearanceStates(new AppearanceState(new NameType('Approved'), $selected), new AppearanceState(new NameType('Other'), $other))), state: new NameType('Approved'));
        $field = (new SignatureField(new PartialFieldName(new TextString('approval')), value: new IndirectReference($pending)))->mergeWidget($widget);
        $page = new PageObject($box, new ResourceDictionary(), null, $this->entry('Annots', new ArrayType(new IndirectReference($field))));
        $widget->bindToPage($page);
        $catalog = new DocumentCatalog((new PageTreeNode())->addKid($page), $this->entry('AcroForm', new IndirectReference(new AcroForm((new FieldTree())->addRoot($field)))));
        $prepared = (new SignatureWriter())->prepare(new FileStructure(new FileHeader(), new FileBody($catalog)), $pending);
        $intent = new SignatureSigningIntent(appearance: new SignatureAppearanceSelection(new TextString('Named appearance'), new IndirectReference($matches ? $selected : $other)));
        if (!$matches) {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('selected normal appearance actually attached');
        }
        self::assertNotEmpty($prepared->signWithPolicy($this->backend(), $intent)->value->bytes);
    }
}

/** Deliberately permits malformed graph links so the emitted-snapshot checks are exercised. */
final class RawSignatureFieldFixture extends DictionaryType
{
    public ?ArrayType $kids = null;
    public function __construct(private PendingSignature $signature)
    {
        parent::__construct();
    }
    public function entries(): iterable
    {
        yield new Entry(new NameType('T'), new TextString('approval'));
        yield new Entry(new NameType('FT'), new NameType('Sig'));
        yield new Entry(new NameType('V'), new IndirectReference($this->signature));
        if ($this->kids !== null) {
            yield new Entry(new NameType('Kids'), $this->kids);
        }
    }
}
