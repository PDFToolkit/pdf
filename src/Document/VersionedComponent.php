<?php

declare(strict_types=1);

namespace PDF\Document;

use PDF\Syntax\FileStructure\EffectivePdfVersion;

/** A native mechanism validates the versions of its actual selected features. */
interface VersionedComponent
{
    public function validateVersion(EffectivePdfVersion $version): void;
}
