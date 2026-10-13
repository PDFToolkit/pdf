<?php

declare(strict_types=1);

namespace PDF\Security;

/** Hardware, remote or native credentials are selected explicitly by the caller. */
interface RecipientDecrypter
{
    public function decrypt(PublicKeyRecipient $recipient): ?string;
}
