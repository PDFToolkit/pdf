<?php

declare(strict_types=1);

namespace PDF\Document\Signatures\Policies;

use PDF\Document\CatalogComponent;
use PDF\Document\VersionedComponent;
use PDF\Specification\Definition\SignatureReferenceDictionaryTable253Definition;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;

/** Table253: direct signature reference/parameters with an explicit indirect FieldMDP Data. */
final class SignatureReference extends ImmutableSignatureDictionary implements CatalogComponent, VersionedComponent
{
    public readonly ?SignatureDataReference $data;
    public function __construct(public readonly SignatureTransform $transform, SignatureDataReference|IndirectReference|null $data = null, SignatureReferenceDigest $digest = SignatureReferenceDigest::SHA1)
    {
        if (($transform->method() === SignatureTransformMethod::FieldMDP) !== ($data !== null)) {
            throw new \InvalidArgumentException('FieldMDP supplies Data explicitly; other methods implicitly transform the catalog.');
        }
        $this->data = $data instanceof IndirectReference ? new SignatureDataReference($data) : $data;
        parent::__construct(
            new SignatureReferenceDictionaryTable253Definition(),
            new Entry(new NameType('Type'), new NameType('SigRef')),
            new Entry(new NameType('TransformMethod'), new NameType($transform->method()->value)),
            new Entry(new NameType('TransformParams'), $transform->parameters()),
            new Entry(new NameType('DigestMethod'), new NameType($digest->value))
        );
    }
    public function entries(): iterable
    {
        yield from parent::entries();
        if ($this->data !== null) {
            yield new Entry(new NameType('Data'), $this->data->reference());
        }
    }
    public function validateCatalog(DictionaryType $catalog): void
    {
        if ($this->data === null) {
            return;
        }
        $target = $this->data->reference()->target();
        $form = $catalog->get('AcroForm');
        $form = $form instanceof IndirectReference ? $form->target() : $form;
        if ($target !== $catalog && $target !== $form) {
            throw new \InvalidArgumentException('FieldMDP Data belongs to the actual catalog or its AcroForm.');
        }
    }
    public function validateVersion(EffectivePdfVersion $version): void
    {
        $version->require(PdfVersion::PDF15, 'Signature modification-detection reference');
    }
}
