<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

use PDF\Syntax\FileStructure\EffectivePdfVersion;

/** A backend validates the algorithms it actually emits before consuming the prepared bytes. */
interface VersionedSignatureBackend extends SignatureBackend
{
    public function validateVersion(EffectivePdfVersion $version, SignatureFormat $format): void;
}
