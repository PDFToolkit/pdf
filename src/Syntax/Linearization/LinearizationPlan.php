<?php

declare(strict_types=1);

namespace PDF\Syntax\Linearization;

use PDF\FileStructure;
use PDF\Document\CatalogComponent;
use PDF\Document\DocumentGraphValidation;
use PDF\Security\FileEncryption;
use PDF\Serialization\SerializationContext;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\FileStructure\CrossReferenceStream;
use PDF\Syntax\FileStructure\ObjectStream;
use PDF\Syntax\FileStructure\CrossReferenceFormat;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\FileStructure\HybridVisibleGraph;
use PDF\Syntax\Filters\Filter;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringType;

/**
 * One graph's Annex F.3 ordering. No input page, reference or dictionary is mutated.
 *
 * Object-number assignment belongs to the writer's remapping context. This plan
 * works on object identity and copies inherited page attributes into serialized
 * page dictionaries, retaining their original shared resource identities.
 */
final class LinearizationPlan
{
    public readonly IndirectReference $root;
    public readonly DictionaryType $catalog;
    public readonly LinearizationSecurity $security;
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
    /** @var list<StreamObject> */
    public readonly array $embeddedFiles;
    /** @var list<list<BasicType>> */
    public readonly array $embeddedFileDependencies;
    /** @var \SplObjectStorage<DictionaryType, DictionaryType> */
    private \SplObjectStorage $normalizedPages;
    /** @var \SplObjectStorage<DictionaryType, null> */
    private \SplObjectStorage $pageNodes;
    /** @var \SplObjectStorage<BasicType, null> */
    private \SplObjectStorage $documentSet;
    /** @var \SplObjectStorage<BasicType, list<Filter>> */
    private \SplObjectStorage $compressed;
    private ObjectIdentityObjects $thumbnailValues;

    public function __construct(public readonly FileStructure $file, LinearizationLimits $limits = new LinearizationLimits(), ?FileEncryption $encryption = null, ?ThumbnailGraphNormalization $thumbnailNormalization = null)
    {
        foreach (['Size', 'Root', 'Prev', 'XRefStm', 'Encrypt'] as $managed) {
            if ($file->trailerEntries?->has($managed)) {
                throw new \LogicException('The linearized writer manages trailer ' . $managed . ' and accepts an initial logical file graph.');
            }
        }
        if (version_compare($file->header->version->value, '1.1', '<')) {
            throw new \LogicException('Annex F.3.2 linearization requires PDF 1.1 or later.');
        }
        $this->root = $file->root ?? $this->findRoot($file);
        $catalog = $this->root->target();
        if (!$catalog instanceof DictionaryType || $this->type($catalog) !== 'Catalog') {
            throw new \LogicException('Linearization requires a document catalog.');
        }
        $this->catalog = $catalog;
        foreach ($catalog->entries() as $entry) {
            $component = $entry->value instanceof IndirectReference ? $entry->value->target() : $entry->value;
            if ($component instanceof CatalogComponent) {
                $component->validateCatalog($catalog);
            }
        }
        $this->security = new LinearizationSecurity($file, $catalog, $encryption);
        $catalogEntries = $this->entries($catalog);
        $pageRoot = $catalogEntries['Pages'] ?? null;
        if (!$pageRoot instanceof IndirectReference || !$pageRoot->target() instanceof DictionaryType) {
            throw new \LogicException('The catalog Pages entry must reference a page tree.');
        }
        $this->normalizedPages = new \SplObjectStorage();
        $this->pageNodes = new \SplObjectStorage();
        $this->documentSet = new \SplObjectStorage();
        $this->compressed = new \SplObjectStorage();
        $this->thumbnailValues = new ObjectIdentityObjects();
        $pages = [];
        $this->visitPageTree($pageRoot->target(), null, [], $pages);
        if ($pages === []) {
            throw new \LogicException('A linearized file requires at least one page.');
        }
        $this->pages = array_values($pages);
        $this->firstPageIndex = $this->initialPage($catalogEntries);
        $original = new SerializationContext();
        $original->prepareFile($file->body, $this->root, $this->security->trailerEntries);
        (new DocumentGraphValidation($original, $catalog))->validate($file->header, $encryption !== null);
        if ($file->crossReferenceFormat !== CrossReferenceFormat::Table) {
            (new EffectivePdfVersion($file->header, $catalog))->require(PdfVersion::PDF15, 'Linearized cross-reference streams');
        }
        $objects = [];
        foreach ($original->objects() as $object) {
            if ($object instanceof ObjectStream) {
                if ($file->crossReferenceFormat === CrossReferenceFormat::Table) {
                    throw new \LogicException('Object streams require a cross-reference-stream layout.');
                }
                foreach ($object->embeddedEntries() as $member) {
                    $type = $member->value instanceof DictionaryType ? $this->type($member->value) : null;
                    if (!in_array($type, ['Catalog', 'Page', 'Sig', 'DocTimeStamp'], true)
                        && $member->value !== $encryption?->dictionary()
                        && !($member->value instanceof DictionaryType && $original->isByteRangeSignature($member->value))) {
                        $this->compressed[$member->value] = $object->filters;
                    }
                }
                continue;
            }
            $objects[] = $object;
        }
        if (count($objects) + 3 > $limits->maximumObjects) {
            throw new \LengthException('The graph exceeds the explicit linearization object capacity.');
        }
        foreach ($objects as $object) {
            if ($original->identifier($object)->generationNumber->value !== 0) {
                throw new \LogicException('All initial linearized objects have generation zero.');
            }
            if ($object instanceof CrossReferenceStream) {
                throw new \LogicException('A logical file graph must not contain an existing cross-reference container.');
            }
        }

        $document = [$catalog];
        $this->documentSet->offsetSet($catalog);
        if ($encryption !== null) {
            $document[] = $encryption->dictionary();
            $this->documentSet->offsetSet($encryption->dictionary());
        }
        foreach (['ViewerPreferences', 'PageMode', 'OpenAction'] as $key) {
            $value = $catalogEntries[$key] ?? null;
            if ($value instanceof IndirectReference) {
                foreach ($this->closure($value->target()) as $object) {
                    if (!$this->documentSet->offsetExists($object)) {
                        $document[] = $object;
                        $this->documentSet->offsetSet($object);
                    }
                }
            }
        }
        $form = $catalogEntries['AcroForm'] ?? null;
        if ($form instanceof IndirectReference) {
            $document[] = $form->target();
            $this->documentSet->offsetSet($form->target());
        }
        if (isset($catalogEntries['Threads'])) {
            foreach ($this->threadObjects($catalogEntries['Threads']) as $object) {
                if (!$this->documentSet->offsetExists($object)) {
                    $document[] = $object;
                    $this->documentSet->offsetSet($object);
                }
            }
        }
        $this->documentObjects = $document;
        $pageClosures = [];
        foreach ($pages as $page) {
            $pageClosures[] = $this->closure($page, true);
        }
        $first = $pageClosures[$this->firstPageIndex];
        if ($first === []) {
            throw new \LogicException('The initial page must have a nonempty dependency group.');
        }
        $outline = $catalogEntries['Outlines'] ?? null;
        $mode = $this->resolve($catalogEntries['PageMode'] ?? null);
        $showOutlines = $mode instanceof NameType && $mode->value === 'UseOutlines';
        $outlineObjects = $outline instanceof IndirectReference ? $this->closure($outline->target()) : [];
        if ($showOutlines && $outlineObjects !== []) {
            $first = [$first[0], ...$this->unique([...$outlineObjects, ...array_slice($first, 1)])];
        }
        $this->firstPageObjects = array_values($first);
        if ($file->crossReferenceFormat === CrossReferenceFormat::Hybrid) {
            $visible = (new HybridVisibleGraph($original, $catalog))->objectNumbers();
            foreach ($original->objects() as $object) {
                if (isset($visible[$original->identifier($object)->objectNumber->value])) {
                    $this->compressed->offsetUnset($object);
                }
            }
            // AnnexF's first-page table has one contiguous live-only subsection.
            // Expand its optional requested members too; all hidden compressed
            // identifiers then belong to the main section's highest range.
            foreach ([...$document, ...$first] as $object) {
                $this->compressed->offsetUnset($object);
            }
        }
        $firstSet = new ObjectIdentitySet(...$first);
        $counts = new \SplObjectStorage();
        foreach ($pageClosures as $index => $closure) {
            if ($index === $this->firstPageIndex) {
                continue;
            }
            foreach ($closure as $object) {
                if (!$firstSet->has($object)) {
                    $counts[$object] = $counts->offsetExists($object) ? $counts[$object] + 1 : 1;
                }
            }
        }
        $remaining = [];
        $shared = [];
        $sharedSet = new ObjectIdentitySet();
        foreach ($pageClosures as $index => $closure) {
            if ($index === $this->firstPageIndex) {
                continue;
            }
            $private = [];
            foreach ($closure as $object) {
                if ($firstSet->has($object)) {
                    continue;
                }
                if ($counts[$object] > 1) {
                    if (!$sharedSet->has($object)) {
                        $shared[] = $object;
                        $sharedSet->add($object);
                    }
                } else {
                    $private[] = $object;
                }
            }
            if ($private === [] || $private[0] !== $pages[$index]) {
                throw new \LogicException('Every page section must begin with its own unshared page object.');
            }
            $remaining[] = $private;
        }
        $used = new ObjectIdentitySet(...[...$document, ...$first, ...$shared]);
        foreach ($remaining as $group) {
            foreach ($group as $object) {
                $used->add($object);
            }
        }
        $embeddedFiles = [];
        $embeddedClosures = [];
        $embeddedUnion = new ObjectIdentitySet();
        $embeddedRoots = new ObjectIdentitySet();
        $embeddedUsage = new ObjectIdentityIntegers();
        foreach ($objects as $object) {
            if ($object instanceof StreamObject && $this->type($object->dictionary()) === 'EmbeddedFile') {
                $embeddedFiles[] = $object;
                $embeddedRoots->add($object);
                $closure = $this->closure($object);
                $embeddedClosures[] = $closure;
                foreach ($closure as $member) {
                    $embeddedUnion->add($member);
                    $embeddedUsage->assign($member, $embeddedUsage->has($member) ? $embeddedUsage->value($member) + 1 : 1);
                }
            }
        }
        $externallyReferenced = new ObjectIdentitySet();
        foreach ($objects as $object) {
            if (!$embeddedUnion->has($object)) {
                foreach ($this->directReferences($object) as $target) {
                    // Filespec EF links locate the payload. Sharing any of its
                    // other dependencies requires the shared-only F.12 form.
                    if ($embeddedUnion->has($target) && !$embeddedRoots->has($target)) {
                        $externallyReferenced->add($target);
                    }
                }
            }
        }
        $embeddedShared = [];
        $promoted = new ObjectIdentitySet();
        foreach ($embeddedClosures as $closure) {
            $isShared = false;
            foreach ($closure as $member) {
                $isShared = $isShared || $used->has($member) || $embeddedUsage->value($member) > 1 || $externallyReferenced->has($member);
            }
            $embeddedShared[] = $isShared;
            if ($isShared) {
                // F.4.6 forbids mixing private objects and shared references in
                // one entry. Promote the whole closure, preserving identities.
                foreach ($closure as $member) {
                    if (!$firstSet->has($member) && !$this->documentSet->offsetExists($member) && !$sharedSet->has($member)) {
                        $shared[] = $member;
                        $sharedSet->add($member);
                        $promoted->add($member);
                    }
                    $used->add($member);
                }
            }
        }
        $remaining = array_map(fn (array $group): array => array_values(array_filter($group, fn (BasicType $object): bool => !$promoted->has($object))), $remaining);
        foreach ($remaining as $index => $group) {
            if ($group === []) {
                throw new \LogicException('An embedded-file closure cannot remove its owning page object.');
            }
        }
        $this->remainingPageObjects = $remaining;
        $this->sharedObjects = $shared;
        $other = [];
        foreach ($objects as $object) {
            if ($object instanceof DictionaryType && $this->type($object) === 'Pages' && !$used->has($object)) {
                $other[] = $object;
                $used->add($object);
            }
        }
        $thumbnails = [];
        $thumbnailUsage = new ObjectIdentityIntegers();
        $thumbnailRoots = [];
        $rootCounts = new ObjectIdentityIntegers();
        foreach ($pages as $index => $page) {
            $thumbnail = $this->entries($page)['Thumb'] ?? null;
            if ($thumbnail === null) {
                continue;
            }
            if (!$thumbnail instanceof IndirectReference || !$thumbnail->target() instanceof StreamObject) {
                throw new \LogicException('A page Thumb must reference an image stream.');
            }
            $thumbnailRoots[$index] = $thumbnail->target();
            $rootCounts->assign($thumbnail->target(), $rootCounts->has($thumbnail->target()) ? $rootCounts->value($thumbnail->target()) + 1 : 1);
            $thumbnails[$index] = $this->closure($thumbnail->target());
            foreach ($thumbnails[$index] as $object) {
                $thumbnailUsage->assign($object, $thumbnailUsage->has($object) ? $thumbnailUsage->value($object) + 1 : 1);
            }
        }
        $copies = (new ThumbnailGraphNormalization())->copies($objects, $this->pages, $thumbnails, $used);
        $seenRoots = new ObjectIdentitySet();
        foreach ($thumbnailRoots as $index => $root) {
            $sharedRoot = $rootCounts->value($root) > 1 || $thumbnailUsage->value($root) > $rootCounts->value($root);
            $mixed = false;
            foreach ($thumbnails[$index] as $object) {
                $mixed = $mixed || $copies->needsCopy($object);
            }
            if (($mixed || $sharedRoot) && $thumbnailNormalization === null) {
                throw new \LogicException('Reused or mixed thumbnail dependencies require explicit ThumbnailGraphNormalization.');
            }
            if ($thumbnailNormalization !== null) {
                $copy = $copies->root($root);
                if ($copies->needsCopy($root) || $seenRoots->has($root) || $thumbnailUsage->value($root) > $rootCounts->value($root)) {
                    $this->normalizedPages[$this->pages[$index]] = $this->normalizedPages[$this->pages[$index]]->with(new Entry(new NameType('Thumb'), new IndirectReference($copy)));
                    $thumbnails[$index] = $this->closure($copy);
                } else {
                    // Keep this source identity once, rebasing only its exclusive
                    // thumbnail representation's mixed dependency references.
                    $this->thumbnailValues->assign($root, $copy);
                    $thumbnails[$index] = $this->closure($root);
                }
            }
            $seenRoots->add($root);
        }
        $thumbnailUsage = new ObjectIdentityIntegers();
        foreach ($thumbnails as $closure) {
            foreach ($closure as $object) {
                if ($used->has($object)) {
                    throw new \LogicException('Normalized thumbnail dependencies must belong exclusively to thumbnail groups.');
                }
                $thumbnailUsage->assign($object, $thumbnailUsage->has($object) ? $thumbnailUsage->value($object) + 1 : 1);
            }
        }
        $thumbnailPrivate = [];
        $thumbnailShared = [];
        foreach ($thumbnails as $index => $closure) {
            $private = [];
            foreach ($closure as $object) {
                if ($thumbnailUsage->value($object) > 1) {
                    if (!$used->has($object)) {
                        $thumbnailShared[] = $object;
                        $used->add($object);
                    }
                } else {
                    $private[] = $object;
                    $other[] = $object;
                    $used->add($object);
                }
            }
            if ($private === []) {
                throw new \LogicException('Each thumbnail hint requires its own image object.');
            }
            $thumbnailPrivate[$index] = $private;
        }
        array_push($other, ...$thumbnailShared);
        $this->thumbnailObjects = $thumbnailPrivate;
        $this->thumbnailSharedObjects = $thumbnailShared;
        $categories = [];
        if ($outlineObjects !== []) {
            $categories['O'] = $outlineObjects;
        }
        $threadInformation = [];
        foreach ($document as $object) {
            if ($object instanceof DictionaryType && $this->type($object) === 'Thread') {
                $info = $this->entries($object)['I'] ?? null;
                if ($info instanceof IndirectReference) {
                    array_push($threadInformation, ...$this->closure($info->target()));
                }
            }
        }
        if ($threadInformation !== []) {
            $categories['A'] = $this->unique($threadInformation);
        }
        $names = $this->resolve($catalogEntries['Names'] ?? null);
        $dests = $catalogEntries['Dests'] ?? ($names instanceof DictionaryType ? ($this->entries($names)['Dests'] ?? null) : null);
        if ($dests instanceof IndirectReference) {
            $categories['E'] = $this->closure($dests->target());
        }
        $info = $file->trailerEntries?->get('Info');
        if ($info instanceof IndirectReference) {
            $categories['I'] = $this->closure($info->target());
        }
        foreach (['PageLabels' => 'L', 'StructTreeRoot' => 'C'] as $key => $hint) {
            $value = $catalogEntries[$key] ?? null;
            if ($value instanceof IndirectReference) {
                $categories[$hint] = $this->closure($value->target());
            }
        }
        if ($form !== null) {
            $formDictionary = $this->resolve($form);
            if ($formDictionary instanceof DictionaryType) {
                $fields = $this->entries($formDictionary)['Fields'] ?? null;
                $categories['V'] = $fields === null ? [] : $this->referencedClosure($fields);
            }
        }
        $renditions = $names instanceof DictionaryType ? ($this->entries($names)['Renditions'] ?? null) : null;
        if ($renditions instanceof IndirectReference) {
            $categories['R'] = $this->closure($renditions->target());
        }
        $hintObjects = [];
        foreach ($categories as $key => $category) {
            $group = [];
            foreach ($category as $object) {
                if (!$used->has($object)) {
                    $group[] = $object;
                    $other[] = $object;
                    $used->add($object);
                }
            }
            if ($group !== []) {
                $hintObjects[$key] = $group;
            } elseif ($key === 'O' && $showOutlines) {
                $hintObjects[$key] = $outlineObjects;
            } elseif (in_array($key, ['V', 'C', 'R'], true)) {
                // The top-level object is already available in part 4 when all
                // remaining members are shared with page sections.
                $head = $key === 'V' && $form instanceof IndirectReference ? $form->target() : $catalog;
                $hintObjects[$key] = [$head];
            }
        }
        $embedded = [];
        $embeddedDependencies = [];
        foreach ($embeddedFiles as $index => $object) {
            $closure = $embeddedClosures[$index];
            $private = [];
            if (!$embeddedShared[$index]) {
                foreach ($closure as $member) {
                    if ($used->has($member)) {
                        throw new \LogicException('A private embedded-file dependency cannot belong to an earlier object group.');
                    }
                    $private[] = $member;
                    $used->add($member);
                }
            }
            $embedded[] = $private;
            $embeddedDependencies[] = $closure;
        }
        foreach ($objects as $object) {
            if (!$used->has($object)) {
                $other[] = $object;
                $used->add($object);
            }
        }
        foreach ($embedded as $group) {
            array_push($other, ...$group);
        }
        $this->otherObjects = $other;
        $this->embeddedFileObjects = $embedded;
        $this->embeddedFiles = $embeddedFiles;
        $this->embeddedFileDependencies = $embeddedDependencies;
        $this->genericHintObjects = $hintObjects;
        $this->genericHintDependencies = $categories;
    }

    public function serializedValue(BasicType $object): BasicType
    {
        if ($this->thumbnailValues->has($object)) {
            return $this->thumbnailValues->value($object);
        }
        return $object instanceof DictionaryType && $this->normalizedPages->offsetExists($object) ? $this->normalizedPages[$object] : $object;
    }

    public function compresses(BasicType $object): bool
    {
        return $this->compressed->offsetExists($object);
    }

    /** @return list<Filter> */
    public function compressionFilters(BasicType $object): array
    {
        return $this->compressed->offsetExists($object) ? $this->compressed[$object] : [];
    }

    /** @return list<BasicType> */
    public function orderedObjects(): array
    {
        $objects = [];
        foreach ($this->remainingPageObjects as $group) {
            array_push($objects, ...$group);
        }
        return [...$objects, ...$this->sharedObjects, ...$this->otherObjects, ...$this->documentObjects, ...$this->firstPageObjects];
    }

    /** @return list<BasicType> */
    public function pageDependencies(DictionaryType $page): array
    {
        return $this->closure($page, true);
    }

    /** @return array<string, BasicType> */
    public function entries(DictionaryType $dictionary): array
    {
        $result = [];
        foreach ($dictionary->entries() as $entry) {
            $result[$entry->key->value] = $entry->value;
        }
        return $result;
    }

    /**
     * @param array<string, BasicType> $inherited
     * @param list<DictionaryType> $pages
     */
    private function visitPageTree(DictionaryType $node, ?DictionaryType $parent, array $inherited, array &$pages): int
    {
        if ($this->pageNodes->offsetExists($node)) {
            throw new \LogicException('The page tree cannot have cycles or multiply owned nodes.');
        }
        $this->pageNodes->offsetSet($node);
        $entries = $this->entries($node);
        $type = $this->resolve($entries['Type'] ?? null);
        if ($parent !== null) {
            $reference = $entries['Parent'] ?? null;
            if (!$reference instanceof IndirectReference || $reference->target() !== $parent) {
                throw new \LogicException('Page-tree Parent references must match their owning Kids node.');
            }
        }
        foreach (['Resources', 'MediaBox', 'CropBox', 'Rotate'] as $key) {
            if (isset($entries[$key])) {
                $inherited[$key] = $entries[$key];
            }
        }
        if ($type instanceof NameType && $type->value === 'Page') {
            if (!isset($inherited['Resources'], $inherited['MediaBox'])) {
                throw new \LogicException('Each page requires an effective Resources and MediaBox value.');
            }
            $entries['Resources'] = $inherited['Resources'];
            $entries['MediaBox'] = $inherited['MediaBox'];
            $entries['CropBox'] = $inherited['CropBox'] ?? $inherited['MediaBox'];
            $entries['Rotate'] = $inherited['Rotate'] ?? new IntegerType(0);
            $this->normalizedPages[$node] = new DictionaryType(...array_map(fn (string $key, BasicType $value): Entry => new Entry(new NameType($key), $value), array_keys($entries), array_values($entries)));
            $pages[] = $node;
            return 1;
        }
        $kids = $this->resolve($entries['Kids'] ?? null);
        $count = $this->resolve($entries['Count'] ?? null);
        if (!$type instanceof NameType || $type->value !== 'Pages' || !$kids instanceof ArrayType || !$count instanceof IntegerType) {
            throw new \LogicException('Page-tree branches require Type Pages, a Kids array and integer Count.');
        }
        $serializedEntries = $entries;
        foreach (['Resources', 'MediaBox', 'CropBox', 'Rotate'] as $key) {
            unset($serializedEntries[$key]);
        }
        $this->normalizedPages[$node] = new DictionaryType(...array_map(fn (string $key, BasicType $value): Entry => new Entry(new NameType($key), $value), array_keys($serializedEntries), array_values($serializedEntries)));
        $actual = 0;
        foreach ($kids->items as $kid) {
            if (!$kid instanceof IndirectReference || !$kid->target() instanceof DictionaryType) {
                throw new \LogicException('Page-tree Kids must be indirect dictionary references.');
            }
            $actual += $this->visitPageTree($kid->target(), $node, $inherited, $pages);
        }
        if ($count->value !== $actual) {
            throw new \LogicException('Page-tree Count must match its descendant leaves.');
        }
        return $actual;
    }

    /** @return list<BasicType> */
    private function closure(BasicType $root, bool $page = false): array
    {
        $result = [];
        $seen = new \SplObjectStorage();
        $visit = function (BasicType $value, bool $indirect, bool $initial = false) use (&$visit, &$result, $seen, $page): void {
            if ($value instanceof IndirectReference) {
                if ($value->target() instanceof ObjectStream || $value->target() instanceof CrossReferenceStream) {
                    throw new \LogicException('Object/xref containers cannot be content dependencies of a linearization plan.');
                }
                $visit($value->target(), true);
                return;
            }
            if ($seen->offsetExists($value)) {
                return;
            }
            $seen->offsetSet($value);
            if (!$initial && (($value instanceof DictionaryType && in_array($this->type($value), ['Page', 'Pages', 'Catalog'], true)) || $this->documentSet->offsetExists($value))) {
                return;
            }
            if (!$initial && $value instanceof StreamObject && $this->type($value->dictionary()) === 'EmbeddedFile') {
                return;
            }
            if ($indirect) {
                $result[] = $value;
            }
            $serialized = $this->serializedValue($value);
            if ($serialized instanceof DictionaryType) {
                foreach ($this->entries($serialized) as $key => $child) {
                    if ($initial && $page && in_array($key, ['Parent', 'Thumb'], true)) {
                        continue;
                    }
                    $visit($child, false);
                }
            } elseif ($serialized instanceof ArrayType) {
                foreach ($serialized->items as $child) {
                    $visit($child, false);
                }
            } elseif ($serialized instanceof StreamObject) {
                $visit($serialized->dictionary(), false);
                foreach ($serialized->filters as $filter) {
                    if ($filter->parameters() !== null) {
                        $visit($filter->parameters(), false);
                    }
                }
            }
        };
        $visit($root, true, true);
        return $result;
    }

    /** @return list<BasicType> */
    private function referencedClosure(BasicType $value): array
    {
        if ($value instanceof IndirectReference) {
            return $this->closure($value->target());
        }
        if ($value instanceof ArrayType) {
            $objects = [];
            foreach ($value->items as $item) {
                array_push($objects, ...$this->referencedClosure($item));
            }
            return $this->unique($objects);
        }
        if ($value instanceof DictionaryType) {
            $objects = [];
            foreach ($value->entries() as $entry) {
                array_push($objects, ...$this->referencedClosure($entry->value));
            }
            return $this->unique($objects);
        }
        return [];
    }

    /** @return list<BasicType> */
    private function directReferences(BasicType $root): array
    {
        $targets = [];
        $pending = [$root];
        $seen = new ObjectIdentitySet();
        while ($pending !== []) {
            $value = array_pop($pending);
            if ($value instanceof IndirectReference) {
                $targets[] = $value->target();
                continue;
            }
            if ($seen->has($value)) {
                continue;
            }
            $seen->add($value);
            if ($value instanceof StreamObject) {
                $pending[] = $value->dictionary();
                foreach ($value->filters as $filter) {
                    if (($parameters = $filter->parameters()) !== null) {
                        $pending[] = $parameters;
                    }
                }
            } elseif ($value instanceof ArrayType) {
                array_push($pending, ...$value->items);
            } elseif ($value instanceof DictionaryType) {
                foreach ($value->entries() as $entry) {
                    $pending[] = $entry->value;
                }
            }
        }
        return $targets;
    }

    /** @return list<BasicType> */
    private function threadObjects(BasicType $value): array
    {
        $objects = $value instanceof IndirectReference ? [$value->target()] : [];
        $array = $this->resolve($value);
        if (!$array instanceof ArrayType) {
            throw new \LogicException('Catalog Threads must be an array.');
        }
        foreach ($array->items as $thread) {
            if (!$thread instanceof IndirectReference || !$thread->target() instanceof DictionaryType) {
                throw new \LogicException('Threads must contain indirect thread dictionaries.');
            }
            $objects[] = $thread->target();
        }
        return $this->unique($objects);
    }

    /** @param array<string, BasicType> $catalog */
    private function initialPage(array $catalog): int
    {
        $action = $this->resolve($catalog['OpenAction'] ?? null);
        if ($action instanceof DictionaryType) {
            $entries = $this->entries($action);
            $kind = $this->resolve($entries['S'] ?? null);
            if (!$kind instanceof NameType || $kind->value !== 'GoTo') {
                return 0;
            }
            $action = $this->resolve($entries['D'] ?? null);
        }
        if ($action instanceof NameType || $action instanceof StringType) {
            $action = $this->namedDestination($action, $catalog);
        }
        if ($action instanceof DictionaryType) {
            $action = $this->resolve($this->entries($action)['D'] ?? null);
        }
        if (!$action instanceof ArrayType) {
            return 0;
        }
        $page = $action->items[0] ?? null;
        if (!$page instanceof IndirectReference) {
            throw new \LogicException('A local OpenAction destination must refer to a page object.');
        }
        $index = array_search($page->target(), $this->pages, true);
        if ($index === false) {
            throw new \LogicException('OpenAction cannot select a page outside the document.');
        }
        return $index;
    }

    /** @param array<string, BasicType> $catalog */
    private function namedDestination(NameType|StringType $name, array $catalog): BasicType
    {
        $legacy = $this->resolve($catalog['Dests'] ?? null);
        if ($name instanceof NameType && $legacy instanceof DictionaryType) {
            $entries = $this->entries($legacy);
            if (isset($entries[$name->value])) {
                return $this->resolve($entries[$name->value]) ?? throw new \LogicException('A named destination requires a value.');
            }
        }
        if ($name instanceof NameType) {
            throw new \LogicException('A named initial destination must exist in the legacy Dests dictionary.');
        }
        $names = $this->resolve($catalog['Names'] ?? null);
        $tree = $names instanceof DictionaryType ? $this->resolve($this->entries($names)['Dests'] ?? null) : null;
        if (!$tree instanceof DictionaryType) {
            throw new \LogicException('The named initial destination must exist in this document.');
        }
        $pending = [$tree];
        $seen = new ObjectIdentitySet();
        $found = [];
        while ($pending !== []) {
            $node = array_pop($pending);
            if ($seen->has($node)) {
                throw new \LogicException('A named destination tree cannot have cycles or multiply owned nodes.');
            }
            $seen->add($node);
            $entries = $this->entries($node);
            $pairs = $this->resolve($entries['Names'] ?? null);
            if ($pairs instanceof ArrayType) {
                if (count($pairs->items) % 2 !== 0) {
                    throw new \LogicException('A destination name tree contains string/value pairs.');
                }
                for ($index = 0; $index < count($pairs->items); $index += 2) {
                    $key = $this->resolve($pairs->items[$index]);
                    if (!$key instanceof StringType) {
                        throw new \LogicException('Destination tree keys must be strings.');
                    }
                    if ($key->value === $name->value) {
                        $found[] = $this->resolve($pairs->items[$index + 1]);
                    }
                }
            }
            $kids = $this->resolve($entries['Kids'] ?? null);
            if ($kids instanceof ArrayType) {
                foreach ($kids->items as $kid) {
                    $child = $this->resolve($kid);
                    if (!$child instanceof DictionaryType) {
                        throw new \LogicException('Destination tree Kids must reference dictionaries.');
                    }
                    $pending[] = $child;
                }
            }
        }
        if (count($found) !== 1 || $found[0] === null) {
            throw new \LogicException('The initial destination must have one unambiguous definition.');
        }
        return $found[0];
    }

    private function type(DictionaryType $value): ?string
    {
        $type = $this->resolve($this->entries($value)['Type'] ?? null);
        return $type instanceof NameType ? $type->value : null;
    }

    private function resolve(?BasicType $value): ?BasicType
    {
        return $value instanceof IndirectReference ? $value->target() : $value;
    }

    /**
     * @param list<BasicType> $objects
     * @return list<BasicType>
     */
    private function unique(array $objects): array
    {
        $seen = new ObjectIdentitySet();
        $unique = [];
        foreach ($objects as $object) {
            if (!$seen->has($object)) {
                $unique[] = $object;
                $seen->add($object);
            }
        }
        return $unique;
    }

    private function findRoot(FileStructure $file): IndirectReference
    {
        $roots = [];
        foreach ($file->body->objects() as $object) {
            $value = $object instanceof IndirectObject ? $object->value : $object;
            if ($value instanceof DictionaryType && $this->type($value) === 'Catalog') {
                $roots[] = new IndirectReference($value, $object instanceof IndirectObject ? $object->identifier : null);
            }
        }
        if (count($roots) !== 1) {
            throw new \LogicException('Provide a root reference or exactly one catalog in the file body.');
        }
        return $roots[0];
    }
}
