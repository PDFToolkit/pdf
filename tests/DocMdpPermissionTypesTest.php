<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\Forms\AcroForm;
use PDF\Document\Forms\FieldTree;
use PDF\Document\Forms\PartialFieldName;
use PDF\Document\Forms\SignatureField;
use PDF\Document\ResourceDictionary;
use PDF\Document\Signatures\FrozenSignatureGraph;
use PDF\Document\Signatures\PendingSignature;
use PDF\Document\Signatures\SignatureCapacity;
use PDF\Document\Signatures\SignatureDocumentSigningRules;
use PDF\Document\Signatures\SignatureFormat;
use PDF\FileStructure;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DocMdpPermissionTypesTest extends TestCase
{
    /** @return iterable<string, array{string, bool, NumericType|null, bool}> */
    public static function values(): iterable
    {
        yield 'ISO1 number' => ['1.7', false, new NumericType(2.0), true];
        yield 'ISO2 integer' => ['2.0', false, new IntegerType(2), true];
        yield 'ISO2 real rejected' => ['2.0', false, new NumericType(2.0), false];
        yield 'catalog upgrade uses ISO2' => ['1.7', true, new NumericType(2.0), false];
        yield 'ISO2 missing default' => ['2.0', false, null, true];
        yield 'ISO1 fractional invalid' => ['1.7', false, new NumericType(2.5), false];
        yield 'ISO2 out of range' => ['2.0', false, new IntegerType(4), false];
    }

    #[DataProvider('values')]
    public function testActualFrozenTransformUsesItsEffectiveSourceVersion(string $version, bool $upgrade, ?NumericType $permission, bool $allowed): void
    {
        $parameters = new DictionaryType(...($permission === null ? [] : [new Entry(new NameType('P'), $permission)]));
        $pending = new PendingSignature(new SignatureCapacity(8192), SignatureFormat::Pkcs7Detached, entries: new Entry(new NameType('Reference'), new ArrayType(new DictionaryType(
            new Entry(new NameType('TransformMethod'), new NameType('DocMDP')),
            new Entry(new NameType('TransformParams'), $parameters),
        ))));
        $field = new SignatureField(new PartialFieldName(new TextString('author')), value: new IndirectReference($pending));
        $page = new PageObject(new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(200), new IntegerType(200)), new ResourceDictionary());
        $catalog = new DocumentCatalog(
            (new PageTreeNode())->addKid($page),
            new Entry(new NameType('AcroForm'), new IndirectReference(new AcroForm((new FieldTree())->addRoot($field)))),
            ...($upgrade ? [new Entry(new NameType('Version'), new NameType('2.0'))] : [])
        );
        $revision = (new FileStructure(new FileHeader($version), new FileBody($catalog)))->toRevision();
        $graph = new FrozenSignatureGraph($revision);
        $rules = new SignatureDocumentSigningRules();
        if (!$allowed) {
            $this->expectException(\InvalidArgumentException::class);
        }
        $methods = $rules->methods($graph, $revision->binding($pending)->dictionary);
        self::assertSame(2, $rules->docMdpPermissions($graph, $methods['DocMDP'])->integer());
    }
}
