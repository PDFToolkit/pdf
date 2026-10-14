<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

/** Document-scoped role index, keyed by the canonical structure-element object.
 * @extends \SplObjectStorage<StructureElement, StructureRole>
 */
final class ResolvedStructureRoles extends \SplObjectStorage
{
}
