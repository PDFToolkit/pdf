<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

use PDF\Security\FileEncryption;
use PDF\Security\LegacyPublicKeyEncryption;
use PDF\Security\PublicKeyEncryption;
use PDF\Security\PublicKeyKeyMaterial;
use PDF\Security\PublicKeySubFilter;
use PDF\Security\RecipientDecrypter;

/** Actual CMS decryption, seed/key binding and permission intersection; no implicit credential or PKI lookup. */
final readonly class CertificateUsageRightsAuthentication
{
    /** @var array<string, RecipientDecrypter> */
    private array $filters;

    public function __construct(private RecipientDecrypter $decrypter, CryptFilterUsageRightsCredential ...$filters)
    {
        $selected = [];
        foreach ($filters as $filter) {
            if (isset($selected[$filter->name->value])) {
                throw new \InvalidArgumentException('A crypt filter has one explicit usage-rights credential.');
            }
            $selected[$filter->name->value] = $filter->decrypter;
        }
        $this->filters = $selected;
    }

    /** @return list<UsageRightsSecurityPermissions> */
    public function authenticate(FileEncryption $encryption): array
    {
        try {
            if ($encryption instanceof LegacyPublicKeyEncryption && $this->filters === []) {
                $permission = $this->material($encryption->material, $this->decrypter, $encryption->subFilter === PublicKeySubFilter::Pkcs7S3);
                return $permission === null ? [] : [$permission];
            }
            if (!$encryption instanceof PublicKeyEncryption) {
                throw new \LogicException('Mismatched certificate security handler.');
            }
            $permissions = [];
            $used = [];
            // Authenticate every declared key, including explicit individual-stream filters.
            // Only default document filters carry a document permission word.
            foreach ($encryption->filters->filters() as $filter) {
                $name = $filter->name->value;
                $used[$name] = true;
                $permission = $this->material($filter->material, $this->filters[$name] ?? $this->decrypter, false);
                if ($permission !== null) {
                    $permissions[] = $permission;
                }
            }
            if (array_diff_key($this->filters, $used) !== []) {
                throw new \LogicException('An explicit credential names an absent crypt filter.');
            }
            return $permissions;
        } catch (\Throwable) {
            throw new \LogicException('Usage-rights certificate authentication failed for the actual encrypted source.');
        }
    }

    private function material(PublicKeyKeyMaterial $material, RecipientDecrypter $decrypter, bool $revision2): ?UsageRightsSecurityPermissions
    {
        $result = $material->authenticate($decrypter);
        if ($result === null || !hash_equals($material->key->bytes(), $result->key->bytes())) {
            throw new \LogicException('The selected recipient did not recover the actual crypt key.');
        }
        $permission = $result->permissions;
        return $permission === null ? null : new UsageRightsSecurityPermissions($permission->unsigned, $revision2, $permission->ownerAccess());
    }
}
