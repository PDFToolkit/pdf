<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringType;

/** WTPDF 8.2.5.25, physical page 24: document-local continued-list associations. */
final readonly class WtPdfListChecks
{
    public function __construct(private int $maximumAssociations = 1000000)
    {
        if ($maximumAssociations < 1) {
            throw new \InvalidArgumentException('Continued-list checks require a positive association budget.');
        }
    }

    /**
     * @param list<StructureElement> $elements
     * @param \SplObjectStorage<StructureElement, StructureRole> $roles
     * @param callable(string, int, string, ?StructureElement): void $fail
     */
    public function inspect(array $elements, \SplObjectStorage $roles, AttributeResolver $attributes, WtPdfConformanceLevel $level, callable $fail): void
    {
        $lists = [];
        $ids = [];
        foreach ($elements as $element) {
            if ($roles[$element]->type->value === 'L') {
                $lists[spl_object_id($element)] = $element;
                if ($element->identifier !== null) {
                    $ids[bin2hex($element->identifier->value)] = $element;
                }
            }
        }
        $edges = [];
        $previous = [];
        foreach ($lists as $identity => $list) {
            $from = $this->target($attributes->resolve($list, new NameType('List'), new NameType('ContinuedFrom')));
            if ($from === null) {
                continue;
            }
            $target = $from instanceof StringType ? ($ids[bin2hex($from->value)] ?? null) : null;
            if ($target === null || $target === $list) {
                $fail('8.2.5.25', 24, 'ContinuedFrom must identify another L in the same structure tree.', $list);
                continue;
            }
            $id = spl_object_id($target);
            $previous[$identity] = $id;
            $edges[$identity][] = $id;
            $edges[$id][] = $identity;
        }
        $done = [];
        $operations = 0;
        foreach ($lists as $identity => $list) {
            $chain = [];
            $next = $identity;
            while (isset($previous[$next]) && !isset($done[$next])) {
                if (isset($chain[$next])) {
                    $fail('8.2.5.25', 24, 'ContinuedFrom list associations must not form a cycle.', $list);
                    break;
                }
                $chain[$next] = true;
                $next = $previous[$next];
            }
            $done += $chain;
        }
        $done = [];
        foreach ($edges as $identity => $_neighbors) {
            if (isset($done[$identity])) {
                continue;
            }
            $component = [];
            $queue = [$identity];
            while ($queue !== []) {
                $next = array_pop($queue);
                if (isset($done[$next])) {
                    continue;
                }
                $done[$next] = true;
                $component[] = $next;
                array_push($queue, ...($edges[$next] ?? []));
            }
            foreach ($component as $id) {
                $references = $this->target($lists[$id]->get('Ref'));
                if (!$references instanceof ArrayType) {
                    if ($level === WtPdfConformanceLevel::Reuse) {
                        $fail('8.2.5.25', 24, 'Every known continued-list segment requires Ref for reuse, including the first segment.', $lists[$id]);
                    }
                    continue;
                }
                $targets = [];
                foreach ($references->items as $reference) {
                    if (++$operations > $this->maximumAssociations) {
                        throw new \OverflowException('Continued-list references exceed their explicit cumulative budget.');
                    }
                    $target = $this->target($reference);
                    if ($target instanceof StructureElement) {
                        $targets[spl_object_id($target)] = true;
                    }
                }
                foreach ($component as $target) {
                    if (++$operations > $this->maximumAssociations) {
                        throw new \OverflowException('Continued-list references exceed their explicit cumulative budget.');
                    }
                    if ($target !== $id && !isset($targets[$target])) {
                        $fail('8.2.5.25', 24, 'A present continued-list Ref must identify every other known segment.', $lists[$id]);
                        break;
                    }
                }
            }
        }
    }

    private function target(?BasicType $value): ?BasicType
    {
        return $value instanceof IndirectReference ? $value->target() : $value;
    }
}
