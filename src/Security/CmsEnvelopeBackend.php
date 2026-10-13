<?php

declare(strict_types=1);

namespace PDF\Security;

/** The PDF object model supplies bytes and normalized certificate objects to an explicit CMS processor. */
interface CmsEnvelopeBackend
{
    public function envelope(#[\SensitiveParameter] string $payload, X509Certificate ...$certificates): PublicKeyRecipient;
}
