<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

use PDF\Document\Signatures\Policies\UsageRight;
use PDF\Document\Signatures\Policies\UsageRightsMode;
use PDF\Document\Signatures\Policies\UsageRightsTransform;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringType;

/** ISO1 PDF480–481 / ISO2 PDF589–591: authenticated UR3, explicit authority, exact operation intersection. */
final class AuthenticatedUsageRights
{
    /** @var list<AuthenticatedUsageRightsPolicy> */
    private array $policies = [];

    public function __construct(private readonly ?UsageRightsAuthorization $authorization)
    {
    }

    public function add(FrozenSignatureGraph $source, VerifiedRevisionSignature $signature, DictionaryType $parameters): void
    {
        $signature->validateBinding($source->revision);
        if ($source->revision->encryption !== null) {
            throw new \LogicException('Encrypted usage-rights updates require authenticated security-handler permission facts; this native scope supports clear documents only.');
        }
        $perms = $source->resolve($source->catalog->get('Perms'));
        $target = $perms instanceof DictionaryType ? $perms->get('UR3') : null;
        if (!$target instanceof IndirectReference || (string) $source->record($target)->identifier !== (string) $signature->identifier
            || $source->bindings($signature->identifier) !== []) {
            throw new \LogicException('Authenticated usage rights belong to the actual signed UR3 catalog target outside the field tree.');
        }
        if ($this->authorization === null) {
            throw new \LogicException('Historical usage rights require an explicit granting authority and per-operation authorization.');
        }
        try {
            $permitted = $this->authorization->authority->permits($signature);
        } catch (\Throwable) {
            throw new \LogicException('The selected usage-rights authority failed to authorize the verified signing identity.');
        }
        if (!$permitted) {
            throw new \LogicException('The verified signature identity is not an authorized usage-rights granting identity.');
        }
        $version = $parameters->get('V');
        if ($version !== null && (!$version instanceof NameType || $version->value !== '2.2')) {
            throw new \LogicException('Native authenticated usage rights require UR3 transform version 2.2, including its specified default.');
        }
        $restrict = $parameters->get('P');
        if ($restrict !== null && !$restrict instanceof BooleanType) {
            throw new \LogicException('The usage-rights restriction flag is boolean.');
        }
        $rights = [];
        foreach ($parameters->entries() as $entry) {
            $key = $entry->key->value;
            if (in_array($key, ['Type', 'V', 'P', 'Msg'], true)) {
                if ($key === 'Type' && (!$entry->value instanceof NameType || $entry->value->value !== 'TransformParams')) {
                    throw new \LogicException('The usage-rights parameters retain their declared type.');
                }
                if ($key === 'Msg' && !$entry->value instanceof StringType) {
                    throw new \LogicException('The usage-rights message is a direct text string.');
                }
                continue;
            }
            if (!in_array($key, ['Document', 'Annots', 'Form', 'Signature', 'EF'], true) || !$entry->value instanceof ArrayType) {
                throw new \LogicException('Only recognized, direct usage-rights groups are classified.');
            }
            foreach ($entry->value->items as $name) {
                $right = $name instanceof NameType ? UsageRight::tryFrom($key . '.' . $name->value) : null;
                if ($right === null || in_array($right, $rights, true)) {
                    throw new \LogicException('Authenticated usage rights are distinct known names.');
                }
                $rights[] = $right;
            }
        }
        $this->policies[] = new AuthenticatedUsageRightsPolicy(
            new UsageRightsTransform(UsageRightsMode::ByteRange, $restrict instanceof BooleanType && $restrict->value, null, ...$rights),
            (new EffectivePdfVersion($source->revision->header, $source->catalog))->value
        );
    }

    /** @param list<string> $annotations */
    public function assertChanges(int $fields, array $annotations, bool $signing = false): void
    {
        if ($this->policies === []) {
            return;
        }
        $required = $fields > 0 ? [UsageRight::FillField] : [];
        foreach ($annotations as $operation) {
            $required[] = match ($operation) {
                'create' => UsageRight::CreateAnnotation,
                'modify' => UsageRight::ModifyAnnotation,
                'delete' => UsageRight::DeleteAnnotation,
                default => throw new \LogicException('Usage-rights intersection requires a classified operation.'),
            };
        }
        if ($signing) {
            $required[] = UsageRight::ModifySignature;
        }
        if ($required === []) {
            throw new \LogicException('Usage rights do not authorize unclassified maintenance or a general save.');
        }
        foreach ($this->policies as $policy) {
            foreach ($required as $right) {
                if (!$policy->allows($right) || !$this->authorization?->allows($right)) {
                    throw new \LogicException('Every actual change must belong to both signed usage rights and the explicit application scope.');
                }
                // Check saving per operation: unrelated Annots.Create cannot make
                // an otherwise non-saving Form.FillIn operation persistable.
                if (!$policy->allowsSaving($right)) {
                    throw new \LogicException('The signed usage right does not authorize saving this classified operation.');
                }
            }
        }
    }
}
