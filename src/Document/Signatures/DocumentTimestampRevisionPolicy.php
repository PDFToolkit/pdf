<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

/** ISO 32000-2 12.8.5.3, physical page 606: the complete native maintenance delta. */
final readonly class DocumentTimestampRevisionPolicy
{
    public function validate(FrozenSignatureGraph $current, FrozenSignatureField $field, PendingDocumentTimestamp $pending): void
    {
        (new SignatureRevisionDelta())->validate($current, $field, $pending);
    }
}
