<?php

declare(strict_types=1);

namespace PDF\Syntax\FileStructure;

use PDF\Syntax\Object\DictionaryType;

/** ISO 32000-2:2020 7.5.6, EC3 issue 399 (Completed), physical PDF page 75. */
final readonly class IncrementalVersionPolicy
{
    public function validate(FileRevision $previous, DictionaryType $catalog): void
    {
        $prior = $previous->binding($previous->root->target())->dictionary;
        if ($prior === null) {
            throw new \LogicException('A preceding revision must have a frozen catalog dictionary.');
        }
        $before = new EffectivePdfVersion($previous->header, $prior);
        $after = new EffectivePdfVersion($previous->header, $catalog);
        if ($before->value === PdfVersion::PDF20 && version_compare($after->value->value, $before->value->value, '<')) {
            throw new \LogicException('A PDF 2.0 incremental update cannot lower the effective version by replacing or removing Catalog Version.');
        }
    }
}
