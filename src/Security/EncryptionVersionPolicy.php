<?php

declare(strict_types=1);

namespace PDF\Security;

use PDF\Specification\FeatureApplicability;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;

/** ISO 32000-2:2020, Table 21, physical PDF page 93: R=5 shall not be used. */
final readonly class EncryptionVersionPolicy
{
    public function validate(EffectivePdfVersion $version, DictionaryType $encryption): void
    {
        $filter = $encryption->get('Filter');
        $revision = $encryption->get('R');
        if ($filter instanceof NameType && $filter->value === 'Standard'
            && $revision instanceof NumericType && $revision->value == 5) {
            (new FeatureApplicability(PdfVersion::PDF17, prohibitedIn: PdfVersion::PDF20))
                ->validate($version, 'Standard security handler revision 5');
        }
        if ($version->value === PdfVersion::PDF20 && $filter instanceof NameType && $filter->value === 'Standard') {
            // Table22, physical PDF pages94–95: writers always allow accessibility.
            $permissions = $encryption->get('P');
            if (!$permissions instanceof IntegerType || $permissions->value < -2147483648 || $permissions->value >= 0 || ($permissions->integer() & 0x203) !== 0x200
                || ($permissions->integer() & 0xc0) !== 0xc0
                || ($revision instanceof NumericType && $revision->value >= 3 && ($permissions->integer() & 0xfffff000) !== 0xfffff000)) {
                throw new \LogicException('PDF 2.0 standard encryption permissions require accessibility bit10 and the prescribed reserved bits.');
            }
        }
    }
}
