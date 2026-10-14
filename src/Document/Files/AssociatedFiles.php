<?php

declare(strict_types=1);

namespace PDF\Document\Files;

use PDF\Document\VersionedComponent;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;

/** 14.13, pp.853–856: explicit association array, not an attachment name tree. */
final class AssociatedFiles extends ArrayType implements VersionedComponent
{
    public function __construct(FileSpecification|IndirectReference ...$files)
    {
        if ($files === []) {
            throw new \InvalidArgumentException('An associated-files array requires at least one file specification.');
        }
        foreach ($files as $value) {
            $file = $value instanceof IndirectReference ? $value->target() : $value;
            if (!$file instanceof DictionaryType) {
                throw new \InvalidArgumentException('Associated files require file specification dictionaries.');
            }
            (new FileSpecificationRules())->validate($file);
            $relationship = $file->get('AFRelationship');
            if (!$relationship instanceof NameType || $relationship->value === '') {
                throw new \InvalidArgumentException('Every associated file requires an AFRelationship name (EC3 issue568, Accepted).');
            }
            if (!$value instanceof IndirectReference && ($file->has('EF') || $file->has('RF'))) {
                throw new \InvalidArgumentException('An associated file specification containing EF or RF requires an indirect reference.');
            }
        }
        parent::__construct(...$files);
    }

    public function validateVersion(EffectivePdfVersion $version): void
    {
        $version->require(PdfVersion::PDF20, 'Associated files');
    }
}
