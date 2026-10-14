<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

/** The authority owns uniqueness across requests, processes and restarts. */
interface TimestampSerialSource
{
    public function next(): string;
}
