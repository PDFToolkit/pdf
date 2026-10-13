<?php

declare(strict_types=1);

namespace PDF\Security;

/** The explicit EncryptMetadata flag, supplement PDF pages 18 and 21. */
final readonly class EncryptMetadata
{
    public function __construct(public bool $value)
    {
    }
}
