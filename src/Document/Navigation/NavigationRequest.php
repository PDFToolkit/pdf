<?php

declare(strict_types=1);

namespace PDF\Document\Navigation;

use PDF\Syntax\Object\DictionaryType;

/** Explicit request plan: action declaration first, successor after it; no execution. */
final readonly class NavigationRequest
{
    public function __construct(public NavigationNode $current, public NavigationDirection $direction, public ?DictionaryType $action, public ?NavigationNode $next)
    {
    }
}
