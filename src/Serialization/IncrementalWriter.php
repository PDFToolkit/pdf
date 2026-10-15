<?php

declare(strict_types=1);

namespace PDF\Serialization;

use PDF\Syntax\FileStructure\ByteOffset;
use PDF\Syntax\FileStructure\CrossReferenceEntry;
use PDF\Syntax\FileStructure\CrossReferenceFormat;
use PDF\Syntax\FileStructure\CrossReferenceLimits;
use PDF\Syntax\FileStructure\CrossReferenceSection;
use PDF\Syntax\FileStructure\CrossReferenceStream;
use PDF\Syntax\FileStructure\CrossReferenceStreamCompressedEntry;
use PDF\Syntax\FileStructure\CrossReferenceStreamFreeEntry;
use PDF\Syntax\FileStructure\CrossReferenceStreamInUseEntry;
use PDF\Syntax\FileStructure\CrossReferenceTable;
use PDF\Syntax\FileStructure\FileRevision;
use PDF\Syntax\FileStructure\GeneratedRevisionTimeline;
use PDF\Syntax\FileStructure\CrossReferenceStreamObject;
use PDF\Syntax\FileStructure\ObjectLifecycleTransition;
use PDF\Syntax\FileStructure\FileTrailer;
use PDF\Syntax\FileStructure\IncrementalUpdate;
use PDF\Syntax\FileStructure\ObjectStream;
use PDF\Syntax\FileStructure\RevisionObject;
use PDF\Syntax\FileStructure\ObjectByteSpan;
use PDF\Syntax\FileStructure\HybridReferenceObjects;
use PDF\Syntax\FileStructure\HybridCrossReferenceSection;
use PDF\Security\DocumentMetadataExemption;
use PDF\Security\FileIdentifier;
use PDF\Security\PdfMacReservation;
use PDF\Security\PdfMacTokenComposer;
use PDF\Security\PdfMacSignatureAuthCode;
use PDF\Document\Signatures\PendingSignatureDictionary;
use PDF\Syntax\Object\StreamObject;
use PDF\Document\DocumentGraphValidation;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Syntax\Object\IndirectObject\ObjectNumber;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PDF\Syntax\Object\StringType;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;

/** Append a delta while preserving every byte and reference identity of previous revisions (7.5.6). */
final class IncrementalWriter
{
    public function __construct(private readonly CrossReferenceLimits $limits = new CrossReferenceLimits(), private readonly ?PdfMacTokenComposer $mac = null, private readonly ?PendingSignatureDictionary $macSignature = null)
    {
    }

    public function serialize(IncrementalUpdate $update): FileRevision
    {
        return (new NativeRevisionEmission($this, $update))->revision;
    }

    /** @internal Called only by a per-operation emission capability. */
    public function emit(IncrementalUpdate $update, NativeRevisionEmission $emission): FileRevision
    {
        $previous = $update->previous;
        if (!$previous->isNativeEmission()) {
            throw new \LogicException('Incremental writing requires a native emitted revision; manually supplied bytes and snapshots are not authenticated history.');
        }
        $lifecycle = new ObjectLifecycleTransition($previous, ...$update->objectOperations);
        $security = $previous->encryption === null ? [] : [$previous->encryption];
        $context = new SerializationContext(new ObjectNumber($previous->size->integer()), ...[...$lifecycle->liveObjects, ...$previous->references(), ...$lifecycle->retiredObjects, $previous->freeList, ...$security]);
        foreach ($lifecycle->reusedObjects as $object) {
            $context->stageReused($object);
        }
        $effective = [];
        foreach ($lifecycle->liveObjects as $record) {
            $effective[$record->identifier->objectNumber->value] = $record;
        }
        foreach ($update->body->objects() as $object) {
            if (!$object instanceof IndirectObject && $context->isHistorical($object)) {
                $object = new IndirectObject($object, $context->identifier($object));
            }
            if ($object instanceof IndirectObject) {
                $old = $effective[$object->identifier->objectNumber->value] ?? null;
                if ($object->value instanceof PageObject && $old !== null && $old->value !== $object->value) {
                    // A linearized snapshot pinned inherited values onto the
                    // leaf and removed them from its emitted ancestors. Keep
                    // those explicit values when its construction alias is
                    // staged again; the source graph itself stays unchanged.
                    $page = new DictionaryType(...iterator_to_array($object->value->entries(), false));
                    foreach (['MediaBox', 'Resources', 'CropBox', 'Rotate'] as $key) {
                        $inherited = $old->dictionary?->get($key);
                        if (!$page->has($key) && $inherited !== null) {
                            $page = $page->with(new Entry(new NameType($key), $inherited));
                        }
                    }
                    $object = new IndirectObject($page, $object->identifier);
                }
                if ($old !== null && $this->structural($old)) {
                    throw new \LogicException('Existing structural streams cannot be replaced; create a new object-stream container.');
                }
                if ($old !== null && $old->value === $previous->encryption?->dictionary()) {
                    throw new \LogicException('Incremental revisions must preserve the encryption dictionary and file key.');
                }
                $context->stage($object);
            }
        }
        $trailer = $previous->trailerEntries;
        foreach ($update->trailerEntries?->entries() ?? [] as $entry) {
            $trailer = $trailer->with($entry);
        }
        if ($update->trailerEntries?->has('AuthCode')) {
            throw new \LogicException('AuthCode belongs to an explicit PDF MAC writer operation.');
        }
        $reservation = $this->mac === null || $this->macSignature !== null ? null : new PdfMacReservation($this->mac);
        if ($this->macSignature !== null && $this->mac === null) {
            throw new \LogicException('A PDF MAC signature location requires an explicit MAC composer.');
        }
        if ($this->mac !== null) {
            $this->mac->validateEncryption($previous->encryption ?? throw new \LogicException('PDF MAC requires an encrypted document.'));
        }
        if ($reservation !== null) {
            $trailer = $reservation->trailer($trailer);
        } elseif ($this->macSignature !== null) {
            $trailer = $trailer->with(new Entry(new NameType('AuthCode'), new PdfMacSignatureAuthCode($this->macSignature)));
        } elseif ($trailer->has('AuthCode')) {
            throw new \LogicException('An incremental revision cannot inherit a stale PDF MAC; prepare a fresh operation.');
        }
        $permissions = $previous->encryption?->dictionary()->get('P');
        if ($permissions instanceof IntegerType && ($permissions->integer() & 4096) === 0 && $previous->encryption?->dictionary()->has('KDFSalt') && $this->mac === null) {
            throw new \LogicException('Permission bit13 requires an explicit PDF MAC operation in every revision.');
        }
        $root = $update->root ?? $previous->root;
        // Validate inherited and overridden trailer values before serializing any object.
        new FileTrailer($previous->size, $root, ...iterator_to_array($trailer->entries(), false));
        $this->validateIdentifierChange($previous, $update);
        $context->prepareFile($update->body, $root, $trailer);
        // Reuse values can be unreferenced explicit roots, but their children
        // still need discovery and native graph checks before any bytes emit.
        $context->prepare(...array_values($context->objects()));
        if ($reservation !== null) {
            foreach ($context->objects() as $object) {
                if ($object instanceof PendingSignatureDictionary) {
                    throw new \LogicException('A signed revision requires the PDF MAC to be attached to its signature.');
                }
            }
        }
        foreach ($context->objects() as $number => $object) {
            $old = $effective[$number] ?? null;
            if ($old !== null && $old->value === $previous->encryption?->dictionary()) {
                throw new \LogicException('Incremental revisions must preserve the encryption dictionary and file key, including object-stream members.');
            }
            if ($old !== null && $this->structural($old)) {
                throw new \LogicException('Existing structural streams cannot be replaced through object-stream members.');
            }
            if ($old === null && $context->identifier($object)->generationNumber->value !== 0 && !$lifecycle->reusedNumber($number)) {
                throw new \LogicException('New objects start with generation zero.');
            }
            $effective[$number] = $old === null ? new RevisionObject($context->identifier($object), $object) : $old->replacedBy($object);
        }
        $rootIdentifier = $context->reference($root);
        $catalogRecord = $effective[$rootIdentifier->objectNumber->value] ?? null;
        $catalog = $catalogRecord?->value;
        $catalogDictionary = $catalogRecord?->dictionary;
        if (!$catalog instanceof DictionaryType || !($catalogDictionary?->get('Type') instanceof NameType)
            || $catalogDictionary->get('Type')->value !== 'Catalog' || !$catalogDictionary->get('Pages') instanceof IndirectReference) {
            throw new \LogicException('The effective incremental Root must be a catalog with an indirect Pages entry.');
        }
        // Current typed components need their explicit construction identities; unchanged roots use frozen values.
        $validationCatalog = ($context->objects()[$rootIdentifier->objectNumber->value] ?? null) === $catalog ? $catalog : $catalogDictionary;
        (new \PDF\Syntax\FileStructure\IncrementalVersionPolicy())->validate($previous, $catalogDictionary);
        (new DocumentGraphValidation($context, $validationCatalog, ...array_values($effective)))->validate($previous->header, $previous->encryption !== null, $previous);
        if ($previous->encryption !== null) {
            if (!$previous->encryption->metadata()->value && $catalogDictionary->has('Metadata')) {
                $metadata = $catalogDictionary->get('Metadata');
                if (!$metadata instanceof IndirectReference) {
                    throw new \LogicException('Catalog Metadata must reference the document-level metadata stream.');
                }
                $metadataIdentifier = $context->reference($metadata);
                $metadataStream = ($effective[$metadataIdentifier->objectNumber->value] ?? null)?->value;
                if (!$metadataStream instanceof StreamObject) {
                    throw new \LogicException('The effective Catalog Metadata must be a stream.');
                }
                $context->excludeDocumentMetadata(new DocumentMetadataExemption($metadataStream));
            }
            $id = $trailer->get('ID');
            if (!$id instanceof ArrayType || !($id->items[0] ?? null) instanceof StringType) {
                throw new \LogicException('An encrypted incremental file retains its original file identifier.');
            }
            (new \PDF\Security\EncryptionVersionPolicy())->validate(new \PDF\Syntax\FileStructure\EffectivePdfVersion($previous->header, $catalogDictionary), $previous->encryption->dictionary());
            $previous->encryption->validateFile($previous->header, $catalogDictionary, new FileIdentifier($id->items[0]));
            $this->validateEncryptedTrailer($trailer, $reservation?->dictionary);
        }
        $pagesReference = $catalogDictionary->get('Pages');
        if (!$pagesReference instanceof IndirectReference) {
            throw new \LogicException('The effective Catalog.Pages must remain an indirect reference.');
        }
        $pagesIdentifier = $context->reference($pagesReference);
        $pagesDictionary = ($effective[$pagesIdentifier->objectNumber->value] ?? null)?->dictionary;
        if (!$pagesDictionary?->get('Type') instanceof NameType || $pagesDictionary->get('Type')->value !== 'Pages' || $pagesDictionary->has('Parent')) {
            throw new \LogicException('The effective Pages reference must remain a root /Pages dictionary.');
        }
        $hybrid = $previous->crossReferenceFormat === CrossReferenceFormat::Hybrid ? new HybridReferenceObjects($context, $catalogDictionary, $previous, $rootIdentifier) : null;
        $compressed = [];
        foreach ($context->objects() as $object) {
            if (!$object instanceof ObjectStream) {
                continue;
            }
            if ($previous->crossReferenceFormat === CrossReferenceFormat::Table) {
                throw new \LogicException('Object streams require the inherited cross-reference-stream format.');
            }
            $container = $context->identifier($object);
            if ($container->objectNumber->value < $previous->size->integer()) {
                throw new \LogicException('New object-stream containers require new numbers, never historical live or free numbers (7.5.7).');
            }
            if ($container->generationNumber->value !== 0) {
                throw new \LogicException('An object stream must have generation zero.');
            }
            foreach ($object->embeddedEntries() as $index => $member) {
                if ($lifecycle->reusedNumber($member->objectNumber->value) || $previous->wasReusedNumber($member->objectNumber->value)) {
                    throw new \LogicException('A reused object number cannot become a compressed member, even an unused generation-zero hole (7.5.7).');
                }
                if ($previous->encryption !== null && ($member->value === $previous->encryption->dictionary()
                    || ($member->value instanceof DictionaryType && $context->isByteRangeSignature($member->value)))) {
                    throw new \LogicException('Encryption and byte-range signature dictionaries cannot be in encrypted object streams.');
                }
                $number = $member->objectNumber->value;
                if (isset($compressed[$number])) {
                    throw new \LogicException('An object cannot belong to multiple object streams.');
                }
                $compressed[$number] = new CrossReferenceStreamCompressedEntry(
                    $member->objectNumber,
                    $container->objectNumber,
                    new IntegerType($index),
                );
            }
        }
        $compressed = $hybrid?->compressedEntries() ?? $compressed;
        if ($previous->encryption !== null && isset($compressed[$rootIdentifier->objectNumber->value])
            && (new \PDF\Syntax\FileStructure\EffectivePdfVersion($previous->header, $catalogDictionary))->value === \PDF\Syntax\FileStructure\PdfVersion::PDF20) {
            throw new \LogicException('An encrypted PDF 2.0 catalog cannot be embedded in an object stream (7.5.7, EC3 issue439).');
        }
        $bytes = $previous->bytes;
        $spans = [];
        foreach ($previous->spans() as $span) {
            if ($lifecycle->releasedNumber($span->identifier->objectNumber->value)) {
                continue;
            }
            $spans[$span->identifier->objectNumber->value] = $span;
        }
        foreach (array_keys($compressed) as $number) {
            unset($spans[$number]);
        }
        $entries = array_values($compressed);
        $written = array_fill_keys(array_keys($compressed), true);
        do {
            foreach (array_diff_key($context->objects(), $written) as $number => $object) {
                if ($object instanceof CrossReferenceStreamObject) {
                    throw new \LogicException('Cross-reference streams belong to the incremental writer.');
                }
                $identifier = $context->identifier($object);
                $entries[] = new CrossReferenceStreamInUseEntry($identifier->objectNumber, new ByteOffset(strlen($bytes)), $identifier->generationNumber);
                $value = $hybrid?->serializedValue($object) ?? $object;
                $objectBytes = $identifier . " obj\n" . $context->serializeIndirect($value, $identifier) . "\nendobj\n";
                $spans[$number] = new ObjectByteSpan($identifier, new ByteOffset(strlen($bytes)), strlen($objectBytes));
                $bytes .= $objectBytes;
                $written[$number] = true;
            }
        } while (array_diff_key($context->objects(), $written) !== []);
        $offset = strlen($bytes);
        $maximum = max([$previous->size->integer() - 1, ...array_keys($context->objects())]);
        $reserve = $previous->crossReferenceFormat === CrossReferenceFormat::Table ? 1 : 2;
        if ($maximum > PHP_INT_MAX - $reserve) {
            throw new \OverflowException('Not enough object number space for an incremental revision.');
        }
        $firstAvailable = (new ObjectNumber($maximum))->increment();
        $size = $firstAvailable->value;
        $trailer = $this->updatedIdentifier($previous, $update, $trailer, $bytes, $context);
        $extras = $trailer->with(new Entry(new NameType('Prev'), new IntegerType($previous->crossReferenceOffset->value)));
        $root = new IndirectReference($catalog, $rootIdentifier);
        $hasPendingSignature = false;
        foreach ($context->objects() as $object) {
            $hasPendingSignature = $hasPendingSignature || ($object instanceof PendingSignatureDictionary && !$context->isHistorical($object));
        }
        if ($hybrid !== null && ($this->mac !== null || $hasPendingSignature)) {
            $identifier = new ObjectIdentifier($firstAvailable);
            $fullSize = new IntegerType($firstAvailable->increment()->value);
            $auxiliaryOffset = strlen($bytes);
            $section = new CrossReferenceSection(
                $fullSize,
                $previous->size,
                $lifecycle->freeList->head(),
                $this->limits,
                ...[...$entries, new CrossReferenceStreamInUseEntry($identifier->objectNumber, new ByteOffset($auxiliaryOffset)), $lifecycle->freeList->changesFrom($previous->freeList)]
            );
            $xref = new CrossReferenceStream($fullSize, $root, $trailer->without('AuthCode'), ...$section->entries());
            $context->register($xref, $identifier);
            $xrefBytes = (new IndirectObject($xref, $identifier))->toPdf($context);
            $spans[$identifier->objectNumber->value] = new ObjectByteSpan($identifier, new ByteOffset($auxiliaryOffset), strlen($xrefBytes));
            $bytes .= $xrefBytes;
            $offset = strlen($bytes);
            $hybridExtras = $extras->with(new Entry(new NameType('XRefStm'), new IntegerType($auxiliaryOffset)));
            $fileTrailer = new FileTrailer($fullSize, $root, ...iterator_to_array($hybridExtras->entries(), false));
            $table = (string) (new HybridCrossReferenceSection($section, $identifier->objectNumber))->singleRevision();
            $serializedTrailer = $context->serialize($fileTrailer);
            $trailerOffset = strlen($bytes) + strlen($table) + strlen("trailer\n");
            $bytes .= $table . "trailer\n" . $serializedTrailer . "\n";
        } elseif ($hybrid !== null) {
            $identifier = new ObjectIdentifier($firstAvailable);
            $fullSize = new IntegerType($firstAvailable->increment()->value);
            $placeholder = new CrossReferenceSection(
                $fullSize,
                $previous->size,
                $lifecycle->freeList->head(),
                $this->limits,
                ...[...$entries, new CrossReferenceStreamInUseEntry($identifier->objectNumber, new ByteOffset(0)), $lifecycle->freeList->changesFrom($previous->freeList)]
            );
            $fallback = (string) (new HybridCrossReferenceSection($placeholder, $identifier->objectNumber))->fallback();
            $fallbackTrailer = new FileTrailer($fullSize, $root, ...iterator_to_array($extras->entries(), false));
            $serializedTrailer = $context->serialize($fallbackTrailer);
            $trailerOffset = strlen($bytes) + strlen($fallback) + strlen("trailer\n");
            $bytes .= $fallback . "trailer\n" . $serializedTrailer . "\nstartxref\n" . $offset . "\n%%EOF\n";
            if ($reservation !== null) {
                $bytes = $reservation->finalize($bytes, $trailerOffset, $serializedTrailer)->bytes;
            }
            $auxiliaryOffset = strlen($bytes);
            $section = new CrossReferenceSection(
                $fullSize,
                $previous->size,
                $lifecycle->freeList->head(),
                $this->limits,
                ...[...$entries, new CrossReferenceStreamInUseEntry($identifier->objectNumber, new ByteOffset($auxiliaryOffset)), $lifecycle->freeList->changesFrom($previous->freeList)]
            );
            $xref = new CrossReferenceStream($fullSize, $root, $trailer->without('AuthCode'), ...$section->entries());
            $context->register($xref, $identifier);
            $xrefBytes = (new IndirectObject($xref, $identifier))->toPdf($context);
            $spans[$identifier->objectNumber->value] = new ObjectByteSpan($identifier, new ByteOffset($auxiliaryOffset), strlen($xrefBytes));
            $bytes .= $xrefBytes;
            $updateOffset = strlen($bytes);
            $hybridExtras = $trailer->with(new Entry(new NameType('Prev'), new IntegerType($offset)))
                ->with(new Entry(new NameType('XRefStm'), new IntegerType($auxiliaryOffset)));
            $updateTrailer = new FileTrailer($fullSize, $root, ...iterator_to_array($hybridExtras->entries(), false));
            $updateTable = (string) (new HybridCrossReferenceSection($section, $identifier->objectNumber))->update();
            $serializedTrailer = $context->serialize($updateTrailer);
            $trailerOffset = strlen($bytes) + strlen($updateTable) + strlen("trailer\n");
            $bytes .= $updateTable . "trailer\n" . $serializedTrailer . "\n";
            $offset = $updateOffset;
        } elseif ($previous->crossReferenceFormat === CrossReferenceFormat::Stream) {
            $identifier = new ObjectIdentifier($firstAvailable);
            $entries[] = new CrossReferenceStreamInUseEntry($identifier->objectNumber, new ByteOffset($offset));
            $section = new CrossReferenceSection(new IntegerType($firstAvailable->increment()->value), $previous->size, $lifecycle->freeList->head(), $this->limits, ...[...$entries, $lifecycle->freeList->changesFrom($previous->freeList)]);
            $xref = new CrossReferenceStream($section->size, $root, $extras, ...$section->entries());
            $context->register($xref, $identifier);
            $xrefBytes = (new IndirectObject($xref, $identifier))->toPdf($context);
            $spans[$identifier->objectNumber->value] = new ObjectByteSpan($identifier, new ByteOffset(strlen($bytes)), strlen($xrefBytes));
            $bytes .= $xrefBytes;
            $prefix = $identifier . " obj\n";
            $dictionaryEnd = strpos($xrefBytes, "\nstream\n");
            if ($dictionaryEnd === false) {
                throw new \LogicException('The managed xref stream must contain its explicit dictionary boundary.');
            }
            $serializedTrailer = substr($xrefBytes, strlen($prefix), $dictionaryEnd - strlen($prefix));
            $trailerOffset = $offset + strlen($prefix);
        } else {
            $section = new CrossReferenceSection(new IntegerType($size), $previous->size, $lifecycle->freeList->head(), $this->limits, ...[...$entries, $lifecycle->freeList->changesFrom($previous->freeList)]);
            $traditional = [];
            foreach ($section->entries() as $entry) {
                if ($entry instanceof CrossReferenceStreamInUseEntry) {
                    $traditional[] = new CrossReferenceEntry($entry->number(), $entry->byteOffset, $entry->generation, true);
                } elseif ($entry instanceof CrossReferenceStreamFreeEntry) {
                    $traditional[] = new CrossReferenceEntry($entry->number(), new ByteOffset($entry->fields()[1]), $entry->generation, false);
                }
            }
            $fileTrailer = new FileTrailer($section->size, $root, ...iterator_to_array($extras->entries(), false));
            $table = (string) new CrossReferenceTable(...$traditional);
            $serializedTrailer = $context->serialize($fileTrailer);
            $trailerOffset = strlen($bytes) + strlen($table) + strlen("trailer\n");
            $bytes .= $table . "trailer\n" . $serializedTrailer . "\n";
        }
        $bytes .= "startxref\n" . $offset . "\n%%EOF\n";
        $finalAuthCode = null;
        if ($reservation !== null) {
            $final = $reservation->finalize($bytes, $trailerOffset, $serializedTrailer);
            $bytes = $final->bytes;
            $finalAuthCode = $final->authCode;
            $trailer = $trailer->with(new Entry(new NameType('AuthCode'), $finalAuthCode));
        }
        foreach ($context->objects() as $number => $object) {
            $value = $hybrid?->serializedValue($object) ?? $object;
            $snapshot = $context->serializedSnapshot($value, $context->identifier($object));
            if ($reservation !== null && $finalAuthCode !== null) {
                $snapshot = $reservation->snapshot($snapshot, $finalAuthCode);
            }
            $effective[$number] = isset($effective[$number])
                ? $effective[$number]->replacedBy($value, $snapshot)
                : new RevisionObject($context->identifier($object), $value, $object, $snapshot);
        }
        $effectiveCompressed = $previous->compressedObjects();
        foreach ($lifecycle->retiredObjects as $retired) {
            unset($effectiveCompressed[$retired->object->identifier->objectNumber->value]);
        }
        foreach ($section->entries() as $entry) {
            if ($entry instanceof CrossReferenceStreamCompressedEntry) {
                $effectiveCompressed[$entry->number()] = $entry;
            } elseif ($entry->number() !== 0) {
                unset($effectiveCompressed[$entry->number()]);
            }
        }
        $freeList = $lifecycle->freeList->withSection($section->size, ...$section->entries());
        $revision = new FileRevision(
            $bytes,
            $previous->header,
            new ByteOffset($offset),
            $section->size,
            $root,
            $trailer,
            $previous->crossReferenceFormat,
            $section->freeListHead(),
            ...[$emission, ...array_values($effective), ...array_values($spans), ...$context->references(), ...$lifecycle->retiredObjects, ...array_values($effectiveCompressed), $freeList, ...($previous->encryption === null ? [] : [$previous->encryption]), new GeneratedRevisionTimeline($previous)],
        );
        (new \PDF\Document\Signatures\IncrementalSignaturePermissions())->validate($revision, $update->usageRights, $update->richTextFilling);
        return $revision;
    }

    private function structural(RevisionObject $record): bool
    {
        $type = $record->dictionary?->get('Type');
        return $record->value instanceof CrossReferenceStreamObject || $record->value instanceof ObjectStream
            || ($type instanceof NameType && in_array($type->value, ['ObjStm', 'XRef'], true));
    }

    private function validateIdentifierChange(FileRevision $previous, IncrementalUpdate $update): void
    {
        $old = $previous->trailerEntries->get('ID');
        $new = $update->trailerEntries?->get('ID');
        if ($old instanceof ArrayType && $new instanceof ArrayType) {
            $original = $old->items[0];
            $proposed = $new->items[0];
            if (!$original instanceof StringType || !$proposed instanceof StringType || $original->value !== $proposed->value) {
                throw new \InvalidArgumentException('The original file identifier must be preserved across revisions.');
            }
        }
    }

    private function validateEncryptedTrailer(DictionaryType $trailer, ?DictionaryType $authorizedAuthCode = null): void
    {
        $pending = [];
        foreach ($trailer->entries() as $entry) {
            if ($entry->key->value !== 'ID' && !($entry->key->value === 'AuthCode' && $entry->value === $authorizedAuthCode)) {
                $pending[] = $entry->value;
            }
        }
        $seen = new \SplObjectStorage();
        while ($pending !== []) {
            $value = array_pop($pending);
            if ($value instanceof IndirectReference || $seen->offsetExists($value)) {
                continue;
            }
            $seen->offsetSet($value);
            if ($value instanceof StringType) {
                throw new \LogicException('Direct trailer strings outside ID remain clear; supply an indirect reference instead.');
            }
            if ($value instanceof ArrayType) {
                array_push($pending, ...$value->items);
            } elseif ($value instanceof DictionaryType) {
                foreach ($value->entries() as $entry) {
                    $pending[] = $entry->value;
                }
            }
        }
    }

    private function updatedIdentifier(FileRevision $previous, IncrementalUpdate $update, DictionaryType $trailer, string $bytes, SerializationContext $context): DictionaryType
    {
        $old = $previous->trailerEntries->get('ID');
        if (!$old instanceof ArrayType || $update->trailerEntries?->has('ID')) {
            return $trailer;
        }
        // This is a deterministic file identifier, not an authentication or signature primitive (14.4).
        $changing = substr(hash('sha256', $bytes . $context->serialize($trailer->without('ID')), true), 0, 16);
        return $trailer->with(new Entry(new NameType('ID'), new ArrayType($old->items[0], new HexadecimalString($changing))));
    }
}
