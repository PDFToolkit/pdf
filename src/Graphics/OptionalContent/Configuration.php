<?php

declare(strict_types=1);

namespace PDF\Graphics\OptionalContent;

use PDF\Specification\Definition\OptionalContentConfigurationDictionaryTable101Definition;
use PDF\Specification\SchemaDictionary;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;

/** Coupled configuration declarations; all referenced OCGs are checked against their document. */
final class Configuration extends SchemaDictionary
{
    public const string TABLE = '101';
    /** @var list<UsageApplication> */
    public readonly array $applications;

    /** @param list<UsageApplication> $applications */
    public function __construct(public readonly BaseState $baseState = BaseState::On, public readonly GroupCollection $on = new GroupCollection(), public readonly GroupCollection $off = new GroupCollection(), public readonly Intents $intents = new Intents(Intent::View), public readonly ?GroupOrder $order = null, public readonly ?RadioButtonGroups $radioGroups = null, public readonly GroupCollection $locked = new GroupCollection(), public readonly ListMode $listMode = ListMode::AllPages, public readonly ?TextString $name = null, public readonly ?TextString $creator = null, array $applications = [])
    {
        foreach ($on->groups as $group) {
            if ($off->contains($group)) {
                throw new \InvalidArgumentException('A construction cannot request both ON and OFF for the same OCG.');
            }
        }
        foreach ($applications as $application) {
            if (!$application instanceof UsageApplication) {
                throw new \InvalidArgumentException('Configuration AS values must be explicit usage applications.');
            }
        }
        $this->applications = array_values($applications);
        $entries = [new Entry(new NameType('BaseState'), new NameType($baseState->value)), new Entry(new NameType('ON'), $on), new Entry(new NameType('OFF'), $off), new Entry(new NameType('Intent'), $intents->value()), new Entry(new NameType('Locked'), $locked), new Entry(new NameType('ListMode'), new NameType($listMode->value)), new Entry(new NameType('AS'), new ArrayType(...$this->applications))];
        foreach (['Order' => $order, 'RBGroups' => $radioGroups, 'Name' => $name, 'Creator' => $creator] as $key => $value) {
            if ($value !== null) {
                $entries[] = new Entry(new NameType($key), $value);
            }
        }
        parent::__construct(new OptionalContentConfigurationDictionaryTable101Definition(), ...$entries);
    }

    public function validateDefault(): void
    {
        if ($this->baseState !== BaseState::On || $this->intents->intents !== [Intent::View]) {
            throw new \InvalidArgumentException('The default OCG configuration requires BaseState ON and Intent View (table 101).');
        }
    }

    public function referencedGroups(): GroupCollection
    {
        $groups = [];
        $collections = [$this->on, $this->off, $this->locked, $this->order?->groups() ?? new GroupCollection(), $this->radioGroups?->groups() ?? new GroupCollection()];
        foreach ($this->applications as $application) {
            $collections[] = $application->groups;
        }
        foreach ($collections as $collection) {
            foreach ($collection->groups as $group) {
                $groups[spl_object_id($group)] = $group;
            }
        }
        return new GroupCollection(...array_values($groups));
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('OCG configuration entries are coupled constructor values.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('OCG configuration entries are coupled constructor values.');
    }
}
