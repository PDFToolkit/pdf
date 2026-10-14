<?php

declare(strict_types=1);

namespace PDF\Security;

use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;

/** ISO 32000-2:2020 pp.103–104/Table24, plus EC3 issue475 Completed, physical p.1021. */
final readonly class PublicKeyVersionValidation
{
    public function validate(EffectivePdfVersion $version, PublicKeyKeyMaterial $material, bool $macExtension = false): void
    {
        if ($version->value !== PdfVersion::PDF20 || $material->scope !== PublicKeyRecipientScope::Document) {
            return;
        }
        if ($material->permissionByteOrder !== PublicKeyPermissionByteOrder::MostSignificantFirst) {
            throw new \LogicException('PDF 2.0 document CMS permission words require most-significant-byte-first order.');
        }
        foreach ($material->recipients as $recipient) {
            $permissions = $recipient->permissions ?? throw new \LogicException('PDF 2.0 document CMS recipients require explicit payload permission facts; opaque bytes alone cannot establish them.');
            if (($permissions->unsigned & 0x200) === 0) {
                throw new \LogicException('PDF 2.0 public-key permission bit10 must be set for accessibility.');
            }
            $mask = $macExtension ? 0xffffe000 : 0xfffff000;
            if (($permissions->unsigned & $mask) !== $mask) {
                throw new \LogicException('PDF 2.0 public-key reserved permission bits13–32 must be set (EC3 issue475, Completed).');
            }
        }
    }
}
