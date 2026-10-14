<?php

declare(strict_types=1);

namespace PDF\Document\Navigation;

use PDF\Document\Destinations\Destination;
use PDF\Syntax\Object\ArrayType;

/** An explicit view re-bound to a page after structure-destination resolution. */
final class ExplicitPageDestination extends Destination
{
    public function __construct(ArrayType $value)
    {
        $parameters = new ExplicitDestinationParameters($value);
        parent::__construct($parameters->reference, $parameters->fit, ...$parameters->coordinates);
    }
}
