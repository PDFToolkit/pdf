<?php

declare(strict_types=1);

namespace PDF\Graphics\OptionalContent;

use PDF\Specification\Definition\OptionalContentMembershipDictionaryTable99Definition;
use PDF\Specification\SchemaDictionary;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;

/** OCMD combines fallback policy with an optional, preferred PDF 1.6 VE tree. */
final class OptionalContentMembership extends SchemaDictionary implements OptionalContentObject, \PDF\Document\VersionedComponent
{
    public function validateVersion(\PDF\Syntax\FileStructure\EffectivePdfVersion $version): void
    {
        $version->require($this->expression === null ? \PDF\Syntax\FileStructure\PdfVersion::PDF15 : \PDF\Syntax\FileStructure\PdfVersion::PDF16, 'Optional content membership/expression');
    }

    public const string TABLE = '99';

    public function __construct(public readonly GroupCollection $members = new GroupCollection(), public readonly VisibilityPolicy $policy = VisibilityPolicy::AnyOn, public readonly ?VisibilityExpression $expression = null)
    {
        $entries = [new Entry(new NameType('Type'), new NameType('OCMD')), new Entry(new NameType('OCGs'), $members), new Entry(new NameType('P'), new NameType($policy->value))];
        if ($expression !== null) {
            $entries[] = new Entry(new NameType('VE'), $expression);
        }
        parent::__construct(new OptionalContentMembershipDictionaryTable99Definition(), ...$entries);
    }

    public function groups(): GroupCollection
    {
        $groups = [];
        foreach ([...$this->members->groups, ...($this->expression?->groups()->groups ?? [])] as $group) {
            $groups[spl_object_id($group)] = $group;
        }
        return new GroupCollection(...array_values($groups));
    }

    public function visible(GroupStates $states): bool
    {
        if ($this->expression !== null) {
            return $this->expression->decision($states) ?? true;
        }
        $values = [];
        foreach ($this->members->groups as $group) {
            $value = $states->decision($group);
            if ($value !== null) {
                $values[] = $value;
            }
        }
        if ($values === []) {
            return true;
        }
        return match ($this->policy) {
            VisibilityPolicy::AllOn => !in_array(false, $values, true),
            VisibilityPolicy::AnyOn => in_array(true, $values, true),
            VisibilityPolicy::AnyOff => in_array(false, $values, true),
            VisibilityPolicy::AllOff => !in_array(true, $values, true),
        };
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('OCMD membership and expression are constructor-controlled.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('OCMD membership and expression are constructor-controlled.');
    }
}
