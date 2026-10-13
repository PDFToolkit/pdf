<?php

declare(strict_types=1);

namespace PDF\Graphics\OptionalContent;

use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;

/** ViewState/PrintState/ExportState; PrintState may deliberately remain unspecified. */
final class EventUsage extends UsageEntry
{
    public function __construct(public readonly UsageEvent $event, public readonly ?BooleanType $state, ?NameType $printSubtype = null)
    {
        if (($state === null && $event !== UsageEvent::Print) || ($printSubtype !== null && $event !== UsageEvent::Print)) {
            throw new \InvalidArgumentException('Only print usage may omit its state or specify a print Subtype.');
        }
        $entries = [];
        if ($state !== null) {
            $entries[] = new Entry(new NameType($event->value . 'State'), new NameType($state->value ? 'ON' : 'OFF'));
        }
        if ($printSubtype !== null) {
            $entries[] = new Entry(new NameType('Subtype'), $printSubtype);
        }
        parent::__construct(UsageCategory::from($event->value), ...$entries);
    }
}
