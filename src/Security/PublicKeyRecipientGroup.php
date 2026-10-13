<?php

declare(strict_types=1);

namespace PDF\Security;

/** One CMS envelope groups certificates with equal access rights; no external certificate lookup. */
final readonly class PublicKeyRecipientGroup
{
    /** @var non-empty-list<X509Certificate> */
    public array $certificates;

    public function __construct(public ?PublicKeyPermissions $permissions, X509Certificate ...$certificates)
    {
        if ($certificates === []) {
            throw new \InvalidArgumentException('A recipient group requires at least one explicit certificate.');
        }
        $this->certificates = array_values($certificates);
    }
}
