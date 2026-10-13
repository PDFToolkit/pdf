<?php

declare(strict_types=1);

namespace PDF\Graphics\OptionalContent;

/** Mutable states scoped to one explicit group collection; no process-wide registry. */
final class GroupStates
{
    /** @var \SplObjectStorage<OptionalContentGroup, bool> */
    private \SplObjectStorage $states;
    private Intents $intents;
    private GroupCollection $locked;
    private RadioButtonGroups $radioGroups;

    public function __construct(public readonly GroupCollection $groups, private readonly Configuration $default = new Configuration())
    {
        $default->validateDefault();
        $this->states = new \SplObjectStorage();
        foreach ($groups->groups as $group) {
            $this->states[$group] = true;
        }
        $this->apply($default);
    }

    public function apply(Configuration $configuration): void
    {
        foreach ($configuration->referencedGroups()->groups as $group) {
            $this->assertRegistered($group);
        }
        if ($configuration->baseState !== BaseState::Unchanged) {
            foreach ($this->groups->groups as $group) {
                $this->states[$group] = $configuration->baseState === BaseState::On;
            }
        }
        foreach ($configuration->on->groups as $group) {
            $this->states[$group] = true;
        }
        foreach ($configuration->off->groups as $group) {
            $this->states[$group] = false;
        }
        $this->intents = $configuration->intents;
        $this->locked = $configuration->locked;
        $this->radioGroups = $configuration->radioGroups ?? $this->default->radioGroups ?? new RadioButtonGroups();
    }

    public function state(OptionalContentGroup $group): bool
    {
        $this->assertRegistered($group);
        return $this->states[$group];
    }

    public function decision(OptionalContentGroup $group): ?bool
    {
        $this->assertRegistered($group);
        return $this->intents->includes($group->intents) ? $this->states[$group] : null;
    }

    /** Programmatic changes are allowed even for groups locked in the user interface. */
    public function set(OptionalContentGroup $group, bool $on): void
    {
        $this->assertRegistered($group);
        $this->states[$group] = $on;
    }

    public function setFromUser(OptionalContentGroup $group, bool $on): void
    {
        $this->assertRegistered($group);
        if ($this->locked->contains($group)) {
            throw new \LogicException('The group is locked in this configuration user interface.');
        }
        $off = [];
        if ($on) {
            foreach ($this->radioGroups->collections as $collection) {
                if ($collection->contains($group)) {
                    foreach ($collection->groups as $other) {
                        if ($other !== $group) {
                            if ($this->locked->contains($other) && $this->state($other)) {
                                throw new \LogicException('A radio change cannot disable a locked ON group.');
                            }
                            $off[spl_object_id($other)] = $other;
                        }
                    }
                }
            }
        }
        foreach ($off as $other) {
            $this->states[$other] = false;
        }
        $this->states[$group] = $on;
    }

    private function assertRegistered(OptionalContentGroup $group): void
    {
        if (!$this->states->offsetExists($group)) {
            throw new \InvalidArgumentException('An OCG state must belong to this explicit document group collection.');
        }
    }
}
