<?php

declare(strict_types=1);

namespace PDF\Document\Signatures\Policies;

use PDF\Document\VersionedComponent;
use PDF\Specification\Definition\DocMDPTransformParametersDictionaryTable254Definition;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;

/** ISO 12.8.2.2, PDF pp.478–479: author modification policy with the exact name /1.2. */
final class DocMdpTransform extends ImmutableSignatureDictionary implements SignatureTransform, VersionedComponent
{
    public function __construct(public readonly DocMdpPermissions $permissions = DocMdpPermissions::FormFillingTemplatesAndSigning)
    {
        parent::__construct(
            new DocMDPTransformParametersDictionaryTable254Definition(),
            new Entry(new NameType('Type'), new NameType('TransformParams')),
            new Entry(new NameType('P'), new IntegerType($permissions->value)),
            new Entry(new NameType('V'), new NameType('1.2'))
        );
    }
    public function method(): SignatureTransformMethod
    {
        return SignatureTransformMethod::DocMDP;
    }
    public function parameters(): self
    {
        return $this;
    }
    public function validateVersion(EffectivePdfVersion $version): void
    {
        $version->require(PdfVersion::PDF15, 'DocMDP signature reference');
    }
}
