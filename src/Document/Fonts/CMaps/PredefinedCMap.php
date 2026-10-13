<?php

declare(strict_types=1);

namespace PDF\Document\Fonts\CMaps;

use PDF\Syntax\Object\NameType;
use PDF\Syntax\FileStructure\PdfVersion;

/** Every predefined CMap name from ISO table 118, PDF pp.281–283; mapping programs remain reader resources. */
enum PredefinedCMap: string
{
    case GbEucH = 'GB-EUC-H';
    case GbEucV = 'GB-EUC-V';
    case GbpcEucH = 'GBpc-EUC-H';
    case GbpcEucV = 'GBpc-EUC-V';
    case GbkEucH = 'GBK-EUC-H';
    case GbkEucV = 'GBK-EUC-V';
    case GbkpEucH = 'GBKp-EUC-H';
    case GbkpEucV = 'GBKp-EUC-V';
    case Gbk2kH = 'GBK2K-H';
    case Gbk2kV = 'GBK2K-V';
    case UniGbUcs2H = 'UniGB-UCS2-H';
    case UniGbUcs2V = 'UniGB-UCS2-V';
    case UniGbUtf16H = 'UniGB-UTF16-H';
    case UniGbUtf16V = 'UniGB-UTF16-V';
    case B5pcH = 'B5pc-H';
    case B5pcV = 'B5pc-V';
    case HkscsB5H = 'HKscs-B5-H';
    case HkscsB5V = 'HKscs-B5-V';
    case EtenB5H = 'ETen-B5-H';
    case EtenB5V = 'ETen-B5-V';
    case EtenmsB5H = 'ETenms-B5-H';
    case EtenmsB5V = 'ETenms-B5-V';
    case CnsEucH = 'CNS-EUC-H';
    case CnsEucV = 'CNS-EUC-V';
    case UniCnsUcs2H = 'UniCNS-UCS2-H';
    case UniCnsUcs2V = 'UniCNS-UCS2-V';
    case UniCnsUtf16H = 'UniCNS-UTF16-H';
    case UniCnsUtf16V = 'UniCNS-UTF16-V';
    case Rksj83pvH = '83pv-RKSJ-H';
    case Rksj90msH = '90ms-RKSJ-H';
    case Rksj90msV = '90ms-RKSJ-V';
    case Rksj90mspH = '90msp-RKSJ-H';
    case Rksj90mspV = '90msp-RKSJ-V';
    case Rksj90pvH = '90pv-RKSJ-H';
    case AddRksjH = 'Add-RKSJ-H';
    case AddRksjV = 'Add-RKSJ-V';
    case EucH = 'EUC-H';
    case EucV = 'EUC-V';
    case ExtRksjH = 'Ext-RKSJ-H';
    case ExtRksjV = 'Ext-RKSJ-V';
    case H = 'H';
    case V = 'V';
    case UniJisUcs2H = 'UniJIS-UCS2-H';
    case UniJisUcs2V = 'UniJIS-UCS2-V';
    case UniJisUcs2HwH = 'UniJIS-UCS2-HW-H';
    case UniJisUcs2HwV = 'UniJIS-UCS2-HW-V';
    case UniJisUtf16H = 'UniJIS-UTF16-H';
    case UniJisUtf16V = 'UniJIS-UTF16-V';
    case KscEucH = 'KSC-EUC-H';
    case KscEucV = 'KSC-EUC-V';
    case KscmsUhcH = 'KSCms-UHC-H';
    case KscmsUhcV = 'KSCms-UHC-V';
    case KscmsUhcHwH = 'KSCms-UHC-HW-H';
    case KscmsUhcHwV = 'KSCms-UHC-HW-V';
    case KscpcEucH = 'KSCpc-EUC-H';
    case UniKsUcs2H = 'UniKS-UCS2-H';
    case UniKsUcs2V = 'UniKS-UCS2-V';
    case UniKsUtf16H = 'UniKS-UTF16-H';
    case UniKsUtf16V = 'UniKS-UTF16-V';
    case IdentityH = 'Identity-H';
    case IdentityV = 'Identity-V';

    public function name(): CMapName
    {
        return new CMapName(new NameType($this->value));
    }

    /** Table 119, PDF pp.283–284: a dash denotes unavailable names in earlier PDF profiles. */
    public function minimumVersion(): PdfVersion
    {
        return match ($this) {
            self::UniGbUtf16H, self::UniGbUtf16V, self::UniCnsUtf16H, self::UniCnsUtf16V,
            self::UniJisUtf16H, self::UniJisUtf16V, self::UniKsUtf16H, self::UniKsUtf16V => PdfVersion::PDF15,
            self::GbkpEucH, self::GbkpEucV, self::Gbk2kH, self::Gbk2kV, self::HkscsB5H, self::HkscsB5V => PdfVersion::PDF14,
            self::GbpcEucV, self::GbkEucH, self::GbkEucV, self::UniGbUcs2H, self::UniGbUcs2V,
            self::EtenmsB5H, self::EtenmsB5V, self::UniCnsUcs2H, self::UniCnsUcs2V,
            self::Rksj90mspH, self::Rksj90mspV, self::EucH, self::EucV,
            self::UniJisUcs2H, self::UniJisUcs2V, self::UniJisUcs2HwH, self::UniJisUcs2HwV,
            self::KscmsUhcHwH, self::KscmsUhcHwV, self::UniKsUcs2H, self::UniKsUcs2V => PdfVersion::PDF13,
            default => PdfVersion::PDF12,
        };
    }
    public function mode(): CMapWritingMode
    {
        return $this === self::V || str_ends_with($this->value, '-V') ? CMapWritingMode::Vertical : CMapWritingMode::Horizontal;
    }
    public function ordering(): ?string
    {
        if ($this === self::IdentityH || $this === self::IdentityV) {
            return null;
        }
        if (str_starts_with($this->value, 'GB') || str_starts_with($this->value, 'UniGB')) {
            return 'GB1';
        }
        if (str_starts_with($this->value, 'B5') || str_starts_with($this->value, 'HKscs') || str_starts_with($this->value, 'ETen') || str_starts_with($this->value, 'CNS') || str_starts_with($this->value, 'UniCNS')) {
            return 'CNS1';
        }
        if (str_starts_with($this->value, 'KSC') || str_starts_with($this->value, 'UniKS')) {
            return 'Korea1';
        }
        return 'Japan1';
    }
}
