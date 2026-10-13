<?php

declare(strict_types=1);

namespace PDF\Serialization;

use PDF\FileStructure;
use PDF\Security\FileEncryption;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\FileStructure\ByteOffset;
use PDF\Syntax\FileStructure\CrossReferenceFormat;
use PDF\Syntax\FileStructure\CrossReferenceStreamCompressedEntry;
use PDF\Syntax\FileStructure\FileTrailer;
use PDF\Syntax\FileStructure\FileRevision;
use PDF\Syntax\FileStructure\RevisionObject;
use PDF\Syntax\FileStructure\ObjectByteSpan;
use PDF\Syntax\FileStructure\FreeListState;
use PDF\Syntax\FileStructure\CrossReferenceStreamFreeEntry;
use PDF\Syntax\Linearization\ExtendedGenericHintTable;
use PDF\Syntax\Linearization\EmbeddedFileHintGroup;
use PDF\Syntax\Linearization\EmbeddedFileHintTable;
use PDF\Syntax\Linearization\FixedWidthOffset;
use PDF\Syntax\Linearization\GenericHintTable;
use PDF\Syntax\Linearization\HintTableEntry;
use PDF\Syntax\Linearization\HintStreamExtent;
use PDF\Syntax\Linearization\HintStreamSplit;
use PDF\Syntax\Linearization\LinearizationCrossReferenceTable;
use PDF\Syntax\Linearization\LinearizationCrossReferenceStream;
use PDF\Syntax\Linearization\LinearizationHybridMainTable;
use PDF\Syntax\Linearization\LinearizationLimits;
use PDF\Syntax\Linearization\LinearizationParameters;
use PDF\Syntax\Linearization\LinearizationPlan;
use PDF\Syntax\Linearization\LinearizationLayout;
use PDF\Syntax\Linearization\ObjectIdentityIntegers;
use PDF\Syntax\Linearization\ObjectIdentityBytes;
use PDF\Syntax\Linearization\PageHint;
use PDF\Syntax\Linearization\PageOffsetHintTable;
use PDF\Syntax\Linearization\PrimaryHintStream;
use PDF\Syntax\Linearization\SharedObjectGroup;
use PDF\Syntax\Linearization\SharedObjectHintTable;
use PDF\Syntax\Linearization\SharedObjectReference;
use PDF\Syntax\Linearization\ThumbnailHint;
use PDF\Syntax\Linearization\ThumbnailHintTable;
use PDF\Syntax\Linearization\ThumbnailGraphNormalization;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Syntax\Object\IndirectObject\ObjectNumber;

/** Native Annex F layout and binary hints; no external linearization program is invoked. */
final readonly class LinearizedWriter
{
    public function __construct(private LinearizationLimits $limits = new LinearizationLimits(), private ?HintStreamSplit $hintSplit = null, private ?FileEncryption $encryption = null, private ?ThumbnailGraphNormalization $thumbnailNormalization = null)
    {
    }

    public function serialize(FileStructure $file): string
    {
        return $this->write($file)->bytes;
    }

    public function revision(FileStructure $file): FileRevision
    {
        return $this->write($file);
    }

    private function write(FileStructure $file): FileRevision
    {
        $streamXrefs = $file->crossReferenceFormat === CrossReferenceFormat::Stream;
        $hybridXrefs = $file->crossReferenceFormat === CrossReferenceFormat::Hybrid;
        $plan = new LinearizationLayout(new LinearizationPlan($file, $this->limits, $this->encryption, $this->thumbnailNormalization));
        $trailerEntries = $plan->plan->security->trailerEntries;
        $second = [];
        foreach ($plan->remainingPageObjects as $group) {
            array_push($second, ...$group);
        }
        array_push($second, ...$plan->sharedObjects, ...$plan->otherObjects);
        $first = [...$plan->documentObjects, ...$plan->firstPageObjects];
        $mainXrefNumber = count($second) + 1;
        $linearNumber = count($second) + count($plan->compressedSecondObjects) + ($streamXrefs || $hybridXrefs ? 2 : 1);
        $firstXrefNumber = $linearNumber + 1;
        $hintNumber = $linearNumber + count($first) + ($streamXrefs ? 2 : 1);
        $overflowCount = $this->hintSplit === null ? 0 : 1;
        $firstMemberNumber = $hintNumber + 1 + $overflowCount;
        $size = $firstMemberNumber + count($plan->compressedFirstObjects);
        if ($size > $this->limits->maximumObjects) {
            throw new \LengthException('Physical containers and compressed members exceed the explicit object capacity.');
        }
        $context = $plan->plan->security->context();
        $numbers = new ObjectIdentityIntegers();
        $number = 1;
        foreach ($second as $object) {
            $numbers->assign($object, $number);
            $context->register($object, new ObjectIdentifier(new ObjectNumber($number++)));
        }
        $number = $linearNumber + ($streamXrefs ? 2 : 1);
        foreach ($first as $object) {
            $numbers->assign($object, $number);
            $context->register($object, new ObjectIdentifier(new ObjectNumber($number++)));
        }
        if ($number !== $hintNumber) {
            throw new \LogicException('The linearization numbering plan must assign every object exactly once.');
        }
        $memberNumber = $mainXrefNumber + 1;
        foreach ($plan->compressedSecondObjects as $member) {
            $numbers->assign($member, $memberNumber);
            $context->register($member, new ObjectIdentifier(new ObjectNumber($memberNumber++)));
        }
        $memberNumber = $firstMemberNumber;
        foreach ($plan->compressedFirstObjects as $member) {
            $numbers->assign($member, $memberNumber);
            $context->register($member, new ObjectIdentifier(new ObjectNumber($memberNumber++)));
        }
        $serialized = new ObjectIdentityBytes();
        foreach ([...$second, ...$first] as $object) {
            $serialized->assign($object, $this->indirect($numbers->value($object), $plan->serializedValue($object), $context));
        }
        if (count($context->objects()) !== count($second) + count($first) + count($plan->compressedFirstObjects) + count($plan->compressedSecondObjects)) {
            throw new \LogicException('All indirect identities must be present in the linearization plan before serialization.');
        }
        $header = (string) $file->header;
        $placeholder = new LinearizationParameters(
            new IntegerType(1),
            new IntegerType(1),
            new IntegerType(1),
            new IntegerType($numbers->value($plan->firstPageObjects[0])),
            new IntegerType(1),
            new IntegerType(count($plan->pages)),
            new IntegerType(1),
            new IntegerType($plan->firstPageIndex),
            $this->hintSplit === null ? null : new HintStreamExtent(new ByteOffset(0), new IntegerType(1)),
        );
        $linearBytes = $this->indirect($linearNumber, $placeholder, $context);
        if (strlen($header . $linearBytes) > 1024) {
            throw new \LengthException('The complete linearization dictionary must fit in the first 1024 bytes.');
        }
        $firstXrefOffset = strlen($header . $linearBytes);
        $firstTrailerPlaceholder = $this->firstTrailer($size, 0, $plan->root, $trailerEntries, $context, $hybridXrefs ? 0 : null);
        $firstXrefPlaceholder = $streamXrefs
            ? $this->indirect($firstXrefNumber, new LinearizationCrossReferenceStream(new IntegerType($linearNumber), new IntegerType($size), $plan->root, $trailerEntries, new FixedWidthOffset(0), ...array_fill(0, count($first) + count($plan->compressedFirstObjects) + 3 + $overflowCount, new ByteOffset(0))), $context)
            : (string) new LinearizationCrossReferenceTable(new IntegerType($linearNumber), ...array_fill(0, count($first) + 2 + $overflowCount, new ByteOffset(0)));
        if ($streamXrefs) {
            $firstTrailerPlaceholder = "startxref\n0\n%%EOF\n";
        }
        $prefix = $header . $linearBytes . $firstXrefPlaceholder . $firstTrailerPlaceholder;
        $offsets = new ObjectIdentityIntegers();
        $documentBytes = $this->block($plan->documentObjects, $serialized, $offsets, strlen($prefix));
        $hintOffset = strlen($prefix . $documentBytes);
        $firstPageBytes = $this->block($plan->firstPageObjects, $serialized, $offsets, $hintOffset);
        $secondBytes = $this->block($second, $serialized, $offsets, $hintOffset + strlen($firstPageBytes));
        $hint = $this->hints($plan, $numbers, $serialized, $offsets);
        $primary = $hint;
        $overflowBytes = '';
        if ($this->hintSplit !== null) {
            [$primary, $overflow] = $this->hintSplit->split($hint);
            $overflowBytes = $this->indirect($hintNumber + 1, $overflow, $context);
        }
        $hintBytes = $this->indirect($hintNumber, $primary, $context);
        $hintLength = strlen($hintBytes);
        $overflowOffset = $hintOffset + $hintLength + strlen($firstPageBytes . $secondBytes);
        $auxiliaryOffset = $overflowOffset + strlen($overflowBytes);
        $auxiliaryLength = $hybridXrefs
            ? strlen($this->indirect($mainXrefNumber, new LinearizationCrossReferenceStream(new IntegerType(0), new IntegerType($linearNumber), null, null, null, ...array_fill(0, count($second) + count($plan->compressedSecondObjects) + 1, new ByteOffset(0))), $context))
            : 0;
        $mainXrefOffset = $auxiliaryOffset + $auxiliaryLength;
        $actual = function (BasicType $object) use ($offsets, $hintOffset, $hintLength): ByteOffset {
            $offset = $offsets->value($object);
            return new ByteOffset($offset >= $hintOffset ? $offset + $hintLength : $offset);
        };
        $firstOffsets = [new ByteOffset(strlen($header))];
        if ($streamXrefs) {
            $firstOffsets[] = new ByteOffset($firstXrefOffset);
        }
        foreach ($first as $object) {
            $firstOffsets[] = $actual($object);
        }
        $firstOffsets[] = new ByteOffset($hintOffset);
        if ($overflowBytes !== '') {
            $firstOffsets[] = new ByteOffset($overflowOffset);
        }
        $firstRecords = $firstOffsets;
        foreach ($plan->compressedFirstObjects as $member) {
            $firstRecords[] = new CrossReferenceStreamCompressedEntry(new ObjectNumber($numbers->value($member)), new ObjectNumber($numbers->value($plan->container($member))), new IntegerType(0));
        }
        $firstXrefObject = $streamXrefs ? new LinearizationCrossReferenceStream(new IntegerType($linearNumber), new IntegerType($size), $plan->root, $trailerEntries, new FixedWidthOffset($mainXrefOffset), ...$firstRecords) : null;
        $firstXref = $firstXrefObject !== null
            ? $this->indirect($firstXrefNumber, $firstXrefObject, $context)
            : (string) new LinearizationCrossReferenceTable(new IntegerType($linearNumber), ...$firstOffsets);
        $mainOffsets = array_map($actual, $second);
        $mainRecords = [...$mainOffsets, new ByteOffset($mainXrefOffset)];
        foreach ($plan->compressedSecondObjects as $member) {
            $mainRecords[] = new CrossReferenceStreamCompressedEntry(new ObjectNumber($numbers->value($member)), new ObjectNumber($numbers->value($plan->container($member))), new IntegerType(0));
        }
        $mainXref = $hybridXrefs
            ? new LinearizationHybridMainTable(new IntegerType(count($plan->compressedSecondObjects)), ...[...$mainOffsets, new ByteOffset($auxiliaryOffset)])
            : new LinearizationCrossReferenceTable(new IntegerType(0), ...$mainOffsets);
        $auxiliaryRecords = [...$mainOffsets, new ByteOffset($auxiliaryOffset)];
        foreach ($plan->compressedSecondObjects as $member) {
            $auxiliaryRecords[] = new CrossReferenceStreamCompressedEntry(new ObjectNumber($numbers->value($member)), new ObjectNumber($numbers->value($plan->container($member))), new IntegerType(0));
        }
        $auxiliaryObject = $hybridXrefs ? new LinearizationCrossReferenceStream(new IntegerType(0), new IntegerType($linearNumber), null, null, null, ...$auxiliaryRecords) : null;
        $auxiliaryBytes = $auxiliaryObject !== null
            ? $this->indirect($mainXrefNumber, $auxiliaryObject, $context)
            : '';
        $mainXrefObject = $streamXrefs ? new LinearizationCrossReferenceStream(new IntegerType(0), new IntegerType($linearNumber), null, null, null, ...$mainRecords) : null;
        $mainXrefBytes = $mainXrefObject === null ? '' : $this->indirect($mainXrefNumber, $mainXrefObject, $context);
        $tail = $mainXrefObject !== null
            ? $mainXrefBytes . "startxref\n" . $firstXrefOffset . "\n%%EOF\n"
            : $auxiliaryBytes . $mainXref . 'trailer' . "\n<< /Size " . $linearNumber . " >>\nstartxref\n" . $firstXrefOffset . "\n%%EOF\n";
        $firstTrailer = $this->firstTrailer($size, $mainXrefOffset, $plan->root, $trailerEntries, $context, $hybridXrefs ? $auxiliaryOffset : null);
        if ($streamXrefs) {
            $firstTrailer = "startxref\n0\n%%EOF\n";
        }
        $parameters = new LinearizationParameters(
            new IntegerType(strlen($prefix . $documentBytes . $hintBytes . $firstPageBytes . $secondBytes . $overflowBytes . $tail)),
            new IntegerType($hintOffset),
            new IntegerType($hintLength),
            new IntegerType($numbers->value($plan->firstPageObjects[0])),
            new IntegerType($hintOffset + $hintLength + strlen($firstPageBytes)),
            new IntegerType(count($plan->pages)),
            new IntegerType($streamXrefs ? $mainXrefOffset : $mainXrefOffset + strlen($mainXref->prefix()) - 1),
            new IntegerType($plan->firstPageIndex),
            $overflowBytes === '' ? null : new HintStreamExtent(new ByteOffset($overflowOffset), new IntegerType(strlen($overflowBytes))),
        );
        $bytes = $header . $this->indirect($linearNumber, $parameters, $context) . $firstXref . $firstTrailer
            . $documentBytes . $hintBytes . $firstPageBytes . $secondBytes . $overflowBytes . $tail;
        if (strlen($bytes) !== $parameters->fileLength->value || strlen($bytes) > $this->limits->maximumFileBytes) {
            throw new \LengthException('The generated file exceeds its exact linearization layout or explicit capacity.');
        }
        $records = [];
        $spans = [];
        foreach ([...$second, ...$first] as $object) {
            $identifier = new ObjectIdentifier(new ObjectNumber($numbers->value($object)));
            $value = $plan->serializedValue($object);
            $records[] = new RevisionObject($identifier, $value, $object, $context->serializedSnapshot($value, $identifier));
            $spans[] = new ObjectByteSpan($identifier, $actual($object), strlen($serialized->value($object)));
        }
        foreach ([...$plan->compressedSecondObjects, ...$plan->compressedFirstObjects] as $member) {
            $identifier = new ObjectIdentifier(new ObjectNumber($numbers->value($member)));
            $value = $plan->plan->serializedValue($member);
            $records[] = new RevisionObject($identifier, $value, $member, $context->serializedSnapshot($value, $identifier));
        }
        $generated = [[$linearNumber, $parameters, strlen($header), strlen($linearBytes)], [$hintNumber, $primary, $hintOffset, $hintLength]];
        if ($overflowBytes !== '') {
            $generated[] = [$hintNumber + 1, $overflow, $overflowOffset, strlen($overflowBytes)];
        }
        if ($firstXrefObject !== null) {
            $generated[] = [$firstXrefNumber, $firstXrefObject, $firstXrefOffset, strlen($firstXref)];
        }
        if ($mainXrefObject !== null) {
            $generated[] = [$mainXrefNumber, $mainXrefObject, $mainXrefOffset, strlen($mainXrefBytes)];
        }
        if ($auxiliaryObject !== null) {
            $generated[] = [$mainXrefNumber, $auxiliaryObject, $auxiliaryOffset, strlen($auxiliaryBytes)];
        }
        foreach ($generated as [$number, $value, $offset, $length]) {
            $identifier = new ObjectIdentifier(new ObjectNumber($number));
            $records[] = new RevisionObject($identifier, $value, $context->serializedSnapshot($value, $identifier));
            $spans[] = new ObjectByteSpan($identifier, new ByteOffset($offset), $length);
        }
        $trailerSnapshot = $context->serializedSnapshot($trailerEntries ?? new DictionaryType())->value;
        if (!$trailerSnapshot instanceof DictionaryType) {
            throw new \LogicException('A revision trailer freezes as a dictionary.');
        }
        return new FileRevision(
            $bytes,
            $file->header,
            new ByteOffset($firstXrefOffset),
            new IntegerType($size),
            new IndirectReference($plan->root->target(), $context->identifier($plan->root->target())),
            $trailerSnapshot,
            $file->crossReferenceFormat,
            new IntegerType(0),
            ...[...$records, ...$spans, ...$context->references(),
                ...array_values(array_filter([...$firstRecords, ...$mainRecords], fn ($record): bool => $record instanceof CrossReferenceStreamCompressedEntry)),
                new FreeListState(new IntegerType($size), new CrossReferenceStreamFreeEntry(new IntegerType(0), new IntegerType(0), new \PDF\Syntax\Object\IndirectObject\GenerationNumber(65535))),
                ...($this->encryption === null ? [] : [$this->encryption])]
        );
    }

    public function writeTo(FileStructure $file, string $path): void
    {
        $output = new PdfFileOutput();
        $output->validatePath($path);
        $output->write($path, $this->serialize($file));
    }

    private function indirect(int $number, BasicType $value, SerializationContext $context): string
    {
        return $number . " 0 obj\n" . $context->serializeIndirect($value, new ObjectIdentifier(new ObjectNumber($number))) . "\nendobj\n";
    }

    private function firstTrailer(int $size, int $previous, IndirectReference $root, ?DictionaryType $extras, SerializationContext $context, ?int $auxiliary = null): string
    {
        // Validate the same Size/Root/Info/ID contracts as an ordinary trailer.
        $trailer = new FileTrailer(new IntegerType($size), $root, ...($extras === null ? [] : iterator_to_array($extras->entries(), false)));
        $context->serialize($trailer);
        $extraBytes = $extras === null ? '' : substr($context->serialize($extras), 2, -2);
        $hybrid = $auxiliary === null ? '' : sprintf(' /XRefStm %010d', $auxiliary);
        return sprintf("trailer\n<< /Size %d /Root %s /Prev %010d%s %s >>\nstartxref\n0\n%%%%EOF\n", $size, $context->serialize($root), $previous, $hybrid, $extraBytes);
    }

    /**
     * @param list<BasicType> $objects
     * @param ObjectIdentityBytes $bytes
     * @param ObjectIdentityIntegers $offsets
     */
    private function block(array $objects, ObjectIdentityBytes $bytes, ObjectIdentityIntegers $offsets, int $start): string
    {
        $text = '';
        foreach ($objects as $object) {
            $offsets->assign($object, $start + strlen($text));
            $text .= $bytes->value($object);
            if ($start + strlen($text) > $this->limits->maximumFileBytes) {
                throw new \LengthException('The generated objects exceed the explicit linearization byte capacity.');
            }
        }
        return $text;
    }

    /**
     * @param ObjectIdentityIntegers $numbers
     * @param ObjectIdentityBytes $bytes
     * @param ObjectIdentityIntegers $offsets
     */
    private function hints(LinearizationLayout $plan, ObjectIdentityIntegers $numbers, ObjectIdentityBytes $bytes, ObjectIdentityIntegers $offsets): PrimaryHintStream
    {
        $sharedObjects = [...$plan->firstPageObjects, ...$plan->sharedObjects];
        $sharedIds = new ObjectIdentityIntegers();
        $sharedHints = [];
        foreach ($sharedObjects as $index => $object) {
            $sharedIds->assign($object, $index);
            $sharedHints[] = new SharedObjectGroup(new ObjectNumber($numbers->value($object)), new ByteOffset($offsets->value($object)), new IntegerType(1), new IntegerType(strlen($bytes->value($object))));
        }
        $pageGroups = [$plan->firstPageObjects, ...$plan->remainingPageObjects];
        $pageHints = [];
        foreach ($pageGroups as $index => $group) {
            $page = $group[0];
            if (!$page instanceof DictionaryType) {
                throw new \LogicException('A page hint starts with its page dictionary.');
            }
            $references = [];
            if ($index !== 0) {
                foreach ($plan->pageDependencies($page) as $dependency) {
                    if ($sharedIds->has($dependency)) {
                        $references[] = new SharedObjectReference(new IntegerType($sharedIds->value($dependency)));
                    }
                }
            }
            $length = array_sum(array_map(fn (BasicType $object): int => strlen($bytes->value($object)), $group));
            $content = $plan->entries($page)['Contents'] ?? null;
            $streams = [];
            $content = $content instanceof IndirectReference ? $content->target() : $content;
            if ($content instanceof ArrayType) {
                foreach ($content->items as $item) {
                    if ($item instanceof IndirectReference) {
                        $streams[] = $item->target();
                    }
                }
            } elseif ($content !== null) {
                $streams[] = $content;
            }
            $starts = [];
            $ends = [];
            foreach ($streams as $stream) {
                if (in_array($stream, $group, true)) {
                    $starts[] = $offsets->value($stream) - $offsets->value($page);
                    $ends[] = $offsets->value($stream) + strlen($bytes->value($stream)) - $offsets->value($page);
                }
            }
            $contentOffset = $starts === [] ? 0 : min($starts);
            $contentLength = $ends === [] ? 0 : max($ends) - $contentOffset;
            $pageHints[] = new PageHint(new IntegerType(count($group)), new ByteOffset($offsets->value($page)), new IntegerType($length), new IntegerType($contentOffset), new IntegerType($contentLength), ...$references);
        }
        $otherHints = [];
        foreach ($plan->genericHintObjects as $key => $group) {
            $first = $group[0];
            $length = 0;
            foreach ($group as $index => $object) {
                if ($offsets->value($object) !== $offsets->value($first) + $length || $numbers->value($object) !== $numbers->value($first) + $index) {
                    throw new \LogicException('A generic hint category must have consecutive object numbers and byte ranges.');
                }
                $length += strlen($bytes->value($object));
            }
            $hint = new GenericHintTable(new ObjectNumber($numbers->value($first)), new ByteOffset($offsets->value($first)), new IntegerType(count($group)), new IntegerType($length));
            if (in_array($key, ['V', 'C', 'R'], true)) {
                $references = [];
                foreach ($plan->genericHintDependencies[$key] as $dependency) {
                    if ($sharedIds->has($dependency)) {
                        $references[] = new SharedObjectReference(new IntegerType($sharedIds->value($dependency)));
                    }
                }
                $hint = new ExtendedGenericHintTable($hint, ...$references);
            }
            $otherHints[] = new HintTableEntry(new NameType($key), $hint);
        }
        if ($plan->thumbnailObjects !== []) {
            $thumbnails = [];
            foreach ($plan->thumbnailObjects as $index => $group) {
                $first = $group[0];
                $length = array_sum(array_map(fn (BasicType $object): int => strlen($bytes->value($object)), $group));
                $thumbnails[] = new ThumbnailHint(new IntegerType($index), new ObjectNumber($numbers->value($first)), new ByteOffset($offsets->value($first)), new IntegerType(count($group)), new IntegerType($length));
            }
            $thumbnailShared = null;
            if ($plan->thumbnailSharedObjects !== []) {
                $group = $plan->thumbnailSharedObjects;
                $length = array_sum(array_map(fn (BasicType $object): int => strlen($bytes->value($object)), $group));
                $thumbnailShared = new GenericHintTable(new ObjectNumber($numbers->value($group[0])), new ByteOffset($offsets->value($group[0])), new IntegerType(count($group)), new IntegerType($length));
            }
            $otherHints[] = new HintTableEntry(new NameType('T'), new ThumbnailHintTable($thumbnailShared, $thumbnails[0], ...array_slice($thumbnails, 1)));
        }
        if ($plan->embeddedFileObjects !== []) {
            $groups = [];
            foreach ($plan->embeddedFileObjects as $index => $group) {
                $references = [];
                foreach ($plan->embeddedFileDependencies[$index] as $dependency) {
                    if ($sharedIds->has($dependency)) {
                        $references[] = new SharedObjectReference(new IntegerType($sharedIds->value($dependency)));
                    }
                }
                $length = array_sum(array_map(fn (BasicType $object): int => strlen($bytes->value($object)), $group));
                $groups[] = new EmbeddedFileHintGroup(new ObjectNumber($numbers->value($plan->embeddedFiles[$index])), new IntegerType(count($group)), new IntegerType($length), ...$references);
            }
            // Zero-object shared-only entries consume no contiguous bytes.
            // Anchor the header at the first private group, or at the first
            // payload itself when every group is represented by shared hints.
            $first = $plan->embeddedFiles[0];
            foreach ($plan->embeddedFileObjects as $group) {
                if ($group !== []) {
                    $first = $group[0];
                    break;
                }
            }
            $otherHints[] = new HintTableEntry(new NameType('B'), new EmbeddedFileHintTable(new ObjectNumber($numbers->value($first)), new ByteOffset($offsets->value($first)), $groups[0], ...array_slice($groups, 1)));
        }
        return new PrimaryHintStream(
            new PageOffsetHintTable($pageHints[0], ...array_slice($pageHints, 1)),
            new SharedObjectHintTable(new IntegerType(count($plan->firstPageObjects)), $sharedHints[0], ...array_slice($sharedHints, 1)),
            ...$otherHints
        );
    }
}
