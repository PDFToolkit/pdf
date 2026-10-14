<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

/** Explicit authority boundary; the core performs no automatic HTTP or certificate retrieval. */
interface TimestampTokenProvider
{
    public function request(TimestampRequest $request): TimestampToken;
}
