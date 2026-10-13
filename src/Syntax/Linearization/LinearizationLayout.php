<?php

declare(strict_types=1);

namespace PDF\Syntax\Linearization;

use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;

/** Physical sections and compressed logical members, explicitly separated as required by F.3.1. */
final class LinearizationLayout
{
    public readonly IndirectReference $root;
    /** @var non-empty-list<DictionaryType> */
    public readonly array $pages;
    public readonly int $firstPageIndex;
    /** @var list<BasicType> */
    public readonly array $documentObjects;
    /** @var non-empty-list<BasicType> */
    public readonly array $firstPageObjects;
    /** @var list<non-empty-list<BasicType>> */
    public readonly array $remainingPageObjects;
    /** @var list<BasicType> */
    public readonly array $sharedObjects;
    /** @var list<BasicType> */
    public readonly array $otherObjects;
    /** @var array<string, list<BasicType>> */
    public readonly array $genericHintObjects;
    /** @var array<string, list<BasicType>> */
    public readonly array $genericHintDependencies;
    /** @var array<int, non-empty-list<BasicType>> */
    public readonly array $thumbnailObjects;
    /** @var list<BasicType> */
    public readonly array $thumbnailSharedObjects;
    /** @var list<list<BasicType>> */
    public readonly array $embeddedFileObjects;
    /** @var list<BasicType> */
    public readonly array $embeddedFiles;
    /** @var list<list<BasicType>> */
    public readonly array $embeddedFileDependencies;
    /** @var list<BasicType> */
    public readonly array $compressedFirstObjects;
    /** @var list<BasicType> */
    public readonly array $compressedSecondObjects;
    private ObjectIdentityObjects $containers;

    public function __construct(public readonly LinearizationPlan $plan)
    {
        $this->containers = new ObjectIdentityObjects();
        $this->root = $plan->root;
        $this->pages = $plan->pages;
        $this->firstPageIndex = $plan->firstPageIndex;
        $this->documentObjects = $this->physical($plan->documentObjects);
        $this->firstPageObjects = [$this->replace($plan->firstPageObjects[0]), ...$this->physical(array_slice($plan->firstPageObjects, 1))];
        $remaining = [];
        foreach ($plan->remainingPageObjects as $group) {
            $remaining[] = [$this->replace($group[0]), ...$this->physical(array_slice($group, 1))];
        }
        $this->remainingPageObjects = $remaining;
        $this->sharedObjects = $this->physical($plan->sharedObjects);
        $this->otherObjects = $this->physical($plan->otherObjects);
        $this->genericHintObjects = array_map(fn (array $objects): array => $this->physical($objects), $plan->genericHintObjects);
        $this->genericHintDependencies = array_map(fn (array $objects): array => $this->physical($objects), $plan->genericHintDependencies);
        $thumbnails = [];
        foreach ($plan->thumbnailObjects as $index => $group) {
            $thumbnails[$index] = [$this->replace($group[0]), ...$this->physical(array_slice($group, 1))];
        }
        $this->thumbnailObjects = $thumbnails;
        $this->thumbnailSharedObjects = $this->physical($plan->thumbnailSharedObjects);
        $embedded = [];
        foreach ($plan->embeddedFileObjects as $group) {
            $embedded[] = $this->physical($group);
        }
        $this->embeddedFileObjects = $embedded;
        $this->embeddedFiles = $this->physical($plan->embeddedFiles);
        $this->embeddedFileDependencies = array_map(fn (array $objects): array => $this->physical($objects), $plan->embeddedFileDependencies);
        $this->compressedFirstObjects = $this->members([...$this->documentObjects, ...$this->firstPageObjects]);
        $second = [];
        foreach ($this->remainingPageObjects as $group) {
            array_push($second, ...$group);
        }
        $this->compressedSecondObjects = $this->members([...$second, ...$this->sharedObjects, ...$this->otherObjects]);
    }

    public function serializedValue(BasicType $physical): BasicType
    {
        return $this->plan->serializedValue($physical);
    }

    /** @return list<BasicType> */
    public function pageDependencies(DictionaryType $page): array
    {
        return $this->physical($this->plan->pageDependencies($page));
    }

    /** @return array<string, BasicType> */
    public function entries(DictionaryType $dictionary): array
    {
        return $this->plan->entries($dictionary);
    }

    public function container(BasicType $logical): LinearizedObjectStream
    {
        $container = $this->containers->value($logical);
        return $container instanceof LinearizedObjectStream ? $container : throw new \LogicException('The logical object must belong to a linearized object stream.');
    }

    /**
     * @param list<BasicType> $objects
     * @return list<BasicType>
     */
    private function physical(array $objects): array
    {
        return array_map(fn (BasicType $object): BasicType => $this->replace($object), $objects);
    }

    private function replace(BasicType $object): BasicType
    {
        if (!$this->plan->compresses($object)) {
            return $object;
        }
        if (!$this->containers->has($object)) {
            $this->containers->assign($object, new LinearizedObjectStream($object, $this->plan->serializedValue($object), ...$this->plan->compressionFilters($object)));
        }
        return $this->containers->value($object);
    }

    /**
     * @param list<BasicType> $objects
     * @return list<BasicType>
     */
    private function members(array $objects): array
    {
        $members = [];
        foreach ($objects as $object) {
            if ($object instanceof LinearizedObjectStream) {
                $members[] = $object->member;
            }
        }
        return $members;
    }
}
