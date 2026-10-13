<?php

declare(strict_types=1);

namespace PDF\Graphics\OptionalContent;

/** The source's AS recommendation/conjunction rules, with explicit outside inputs (p.242). */
final readonly class UsageEvaluator
{
    public function apply(GroupStates $states, Configuration $configuration, UsageEvent $event, UsageEnvironment $environment): void
    {
        $recommendations = [];
        $groups = [];
        foreach ($configuration->applications as $application) {
            if ($application->event !== $event) {
                continue;
            }
            $exactLanguage = false;
            if (in_array(UsageCategory::Language, $application->categories, true)) {
                if ($environment->language === null) {
                    throw new \InvalidArgumentException('Language usage evaluation requires an explicit current system language.');
                }
                foreach ($application->groups->groups as $group) {
                    $usage = $group->usage?->category(UsageCategory::Language);
                    if ($usage instanceof LanguageUsage && $usage->matches($environment->language, true)) {
                        $exactLanguage = true;
                    }
                }
            }
            foreach ($application->groups->groups as $group) {
                $states->state($group);
                $id = spl_object_id($group);
                foreach ($application->categories as $category) {
                    $usage = $group->usage?->category($category);
                    $value = match (true) {
                        $usage instanceof EventUsage => $usage->state?->value,
                        $usage instanceof ZoomUsage => $usage->recommends($environment->magnification),
                        $usage instanceof LanguageUsage && $environment->language !== null => $exactLanguage ? $usage->matches($environment->language, true) : $usage->preferred->value && $usage->matches($environment->language, false),
                        $usage instanceof UserUsage => $this->userMatches($usage, $environment),
                        default => null,
                    };
                    if ($value !== null) {
                        $groups[$id] = $group;
                        $recommendations[$id] = ($recommendations[$id] ?? true) && $value;
                    }
                }
            }
        }
        // Commit only after all scopes and external inputs have been validated.
        foreach ($groups as $id => $group) {
            $states->set($group, $recommendations[$id]);
        }
    }

    private function userMatches(UserUsage $usage, UsageEnvironment $environment): bool
    {
        foreach ($usage->names as $name) {
            foreach ($environment->users[$usage->type->value] ?? [] as $actual) {
                if ($name->text === $actual->text) {
                    return true;
                }
            }
        }
        return false;
    }
}
