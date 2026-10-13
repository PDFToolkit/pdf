<?php

declare(strict_types=1);

namespace PDF\Syntax\Linearization;

/**
 * Explicit permission to renumber a whole, newly serialized object graph.
 *
 * Annex F.3.1, PDF32000_2008.pdf, physical page 685 requires consecutive
 * numbering for two reordered groups. Object identities are preserved; old
 * reference numbers are deliberately replaced within this one context.
 * This contract is incompatible with historical incremental revisions.
 */
final readonly class ObjectIdentifierRemapping
{
}
