<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

use PDF\Syntax\Object\NameType;

/** ISO 32000-2, 14.7.4/14.8.6: bounded transitive role mapping across explicit namespaces. */
final readonly class NamespaceRoleResolver
{
    public function __construct(private StructureNamespaces $namespaces, private RoleMap $defaultRoles = new RoleMap(), private int $maximumMappings = 1024)
    {
        if ($maximumMappings < 1) {
            throw new \InvalidArgumentException('Role resolution requires a positive mapping budget.');
        }
    }

    public function resolve(StructureRole $role): ?StructureRole
    {
        $seen = [];
        $initial = true;
        for ($step = 0; $step < $this->maximumMappings; ++$step) {
            $this->namespaces->require($role->namespace);
            $key = $role->namespaceName() . "\0" . $role->type->value;
            if (isset($seen[$key])) {
                return null;
            }
            $seen[$key] = true;
            $explicitMapping = $role->namespace?->roleMap->target($role->type);
            if ($initial && $explicitMapping !== null) {
                $role = $explicitMapping;
                $initial = false;
                continue;
            }
            $initial = false;
            $namespace = StandardStructureNamespace::tryFrom($role->namespaceName());
            if ($namespace === StandardStructureNamespace::PDF17) {
                // Only the default namespace uses the root RoleMap (14.8.6.2).
                $mapping = $role->namespace === null ? $this->defaultRoles->get($role->type) : null;
                if ($mapping instanceof NameType) {
                    if (StandardStructureType::tryFrom($mapping->value) !== null) {
                        return new StructureRole($mapping);
                    }
                    $role = new StructureRole($mapping);
                    continue;
                }
                if (StandardStructureType::tryFrom($role->type->value) !== null) {
                    return $role;
                }
                if ($explicitMapping !== null) {
                    $role = $explicitMapping;
                    continue;
                }
                return null;
            }
            if ($namespace === StandardStructureNamespace::PDF20 && isset(StructureInclusionTable::RULES[$role->tableType()]) && !in_array($role->type->value, ['StructTreeRoot', 'Hn', 'Art', 'BlockQuote', 'TOC', 'TOCI', 'Index', 'Private', 'Quote', 'Note', 'Reference', 'BibEntry', 'Code'], true)) {
                return $role;
            }
            if ($namespace === StandardStructureNamespace::MathML) {
                return $role;
            }
            $target = $explicitMapping;
            if ($target === null) {
                return null;
            }
            $role = $target;
        }
        throw new \OverflowException('Namespace role mapping exceeds its explicit resolution budget.');
    }
}
