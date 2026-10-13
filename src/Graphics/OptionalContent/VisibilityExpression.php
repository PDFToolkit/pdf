<?php

declare(strict_types=1);

namespace PDF\Graphics\OptionalContent;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;

/** Native recursive VE tree, with ignored intents producing no visibility constraint. */
final class VisibilityExpression extends ArrayType implements \PDF\Document\VersionedComponent
{
    public function validateVersion(\PDF\Syntax\FileStructure\EffectivePdfVersion $version): void
    {
        $version->require(\PDF\Syntax\FileStructure\PdfVersion::PDF16, 'Optional content visibility expression');
    }

    /** @var non-empty-list<OptionalContentGroup|VisibilityExpression> */
    public readonly array $terms;

    public function __construct(public readonly VisibilityOperator $operator, OptionalContentGroup|VisibilityExpression ...$terms)
    {
        if ($terms === [] || ($operator === VisibilityOperator::Not && count($terms) !== 1)) {
            throw new \InvalidArgumentException('VE Not requires one term; And/Or require at least one.');
        }
        $this->terms = array_values($terms);
        parent::__construct(new NameType($operator->value), ...array_map(static fn (OptionalContentGroup|VisibilityExpression $term): IndirectReference|VisibilityExpression => $term instanceof OptionalContentGroup ? new IndirectReference($term) : $term, $this->terms));
    }

    public function groups(): GroupCollection
    {
        $groups = [];
        foreach ($this->terms as $term) {
            foreach (($term instanceof OptionalContentGroup ? [$term] : $term->groups()->groups) as $group) {
                $groups[spl_object_id($group)] = $group;
            }
        }
        return new GroupCollection(...array_values($groups));
    }

    public function decision(GroupStates $states): ?bool
    {
        $values = [];
        foreach ($this->terms as $term) {
            $decision = $term instanceof OptionalContentGroup ? $states->decision($term) : $term->decision($states);
            if ($decision !== null) {
                $values[] = $decision;
            }
        }
        if ($values === []) {
            return null;
        }
        return match ($this->operator) {
            VisibilityOperator::Not => !$values[0],
            VisibilityOperator::And => !in_array(false, $values, true),
            VisibilityOperator::Or => in_array(true, $values, true),
        };
    }
}
