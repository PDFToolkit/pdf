<?php

declare(strict_types=1);

namespace PDF\Document\Files;

/** ISO 32000-2:2020 Table43, physical p.152: standard associated-file relationships. */
enum AFRelationship: string
{
    case Source = 'Source';
    case Data = 'Data';
    case Alternative = 'Alternative';
    case Supplement = 'Supplement';
    case EncryptedPayload = 'EncryptedPayload';
    case FormData = 'FormData';
    case Schema = 'Schema';
    case Unspecified = 'Unspecified';
}
