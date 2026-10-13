<?php

declare(strict_types=1);

require __DIR__ . '/linearization.php';

use PDF\Document\Fonts\StandardFont;
use PDF\Document\Fonts\Type1Font;
use PDF\Document\ResourceDictionary;
use PDF\Document\Resources\Font;
use PDF\FileStructure;
use PDF\Serialization\FileWriter;
use PDF\Serialization\IncrementalWriter;
use PDF\Serialization\LinearizedWriter;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\CrossReferenceFormat;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\FileStructure\FileRevision;
use PDF\Syntax\FileStructure\IncrementalUpdate;
use PDF\Syntax\FileStructure\ObjectStream;
use PDF\Syntax\FileStructure\ObjectStreamEntry;
use PDF\Syntax\FileStructure\ReleaseIndirectObject;
use PDF\Syntax\FileStructure\ReuseObjectNumber;
use PDF\Syntax\Filters\FlateDecode;
use PDF\Syntax\Linearization\HintStreamSplit;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Syntax\Object\IndirectObject\ObjectNumber;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PDF\Syntax\Object\StringObject\LiteralString;

/** @return array{FileStructure, LiteralString, StreamObject} */
function lifecycleFixture(CrossReferenceFormat $format, bool $packed): array
{
    $font = new Type1Font(StandardFont::Helvetica);
    $resources = new ResourceDictionary(new Font(new DictionaryType(new Entry(new NameType('F1'), new IndirectReference($font)))));
    $pages = new PageTreeNode(new Entry(new NameType('MediaBox'), new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(300), new IntegerType(400))), new Entry(new NameType('Resources'), new IndirectReference($resources)));
    foreach ([0, 1] as $page) {
        $pages->addKid(new PageObject(null, null, new StreamObject('BT /F1 12 Tf 20 300 Td (Lifecycle page ' . $page . ') Tj ET', filters: new FlateDecode())));
    }
    $old = new LiteralString('Original indirect string');
    $oldStream = new StreamObject('Original indirect stream', filters: new FlateDecode());
    $catalog = new DocumentCatalog(
        $pages,
        new Entry(new NameType('OriginalString'), new IndirectReference($old)),
        new Entry(new NameType('OriginalStream'), new IndirectReference($oldStream)),
        new Entry(new NameType('Extensions'), new DictionaryType(new Entry(new NameType('ADBE'), new DictionaryType(new Entry(new NameType('BaseVersion'), new NameType('1.7')), new Entry(new NameType('ExtensionLevel'), new IntegerType(3)))))),
    );
    $root = new ObjectIdentifier(new ObjectNumber(80));
    $body = new FileBody(new IndirectObject($catalog, $root));
    if ($packed) {
        $body->addObject(new ObjectStream(new ObjectStreamEntry(new ObjectNumber(70), $old), new FlateDecode()));
        // This unreferenced member remains compressed even in hybrid output.
        // Its classic generation-65535 shadow must never enter the real free list.
        $body->addObject(new ObjectStream(new ObjectStreamEntry(new ObjectNumber(90), new LiteralString('Unreferenced compressed live value')), new FlateDecode()));
    }
    $id = new HexadecimalString(str_repeat('i', 16));
    return [new FileStructure(new FileHeader(), $body, new IndirectReference($catalog, $root), new DictionaryType(new Entry(new NameType('ID'), new ArrayType($id, $id))), $format), $old, $oldStream];
}

/** @return array<string, mixed> */
function lifecycleMetadata(FileRevision $revision, string $filename): array
{
    $free = [];
    foreach ($revision->freeList->entries() as $entry) {
        $free[(string) $entry->number()] = $entry->fields();
    }
    $live = [];
    foreach ($revision->objects() as $record) {
        $live[(string) $record->identifier->objectNumber->value] = $record->identifier->generationNumber->value;
    }
    $compressed = [];
    foreach ($revision->compressedObjects() as $number => $entry) {
        $compressed[(string) $number] = $entry->fields();
    }
    return ['file' => $filename, 'size' => $revision->size->integer(), 'xref' => $revision->crossReferenceOffset->value, 'free' => (object) $free, 'live' => (object) $live, 'compressed' => (object) $compressed, 'retired' => array_map(fn ($record): string => (string) $record->object->identifier, $revision->retiredObjects())];
}

$manifest = [];
foreach (linearizationSecurityCases() as [$securityName, $encryption]) {
    foreach (CrossReferenceFormat::cases() as $format) {
        foreach ($format === CrossReferenceFormat::Table ? [false] : [false, true] as $packed) {
            foreach ([false, true] as $linearized) {
                [$file, $old, $oldStream] = lifecycleFixture($format, $packed);
                $base = $linearized
                    ? (new LinearizedWriter(hintSplit: new HintStreamSplit(new IntegerType(1)), encryption: $encryption))->revision($file)
                    : (new FileWriter(encryption: $encryption))->revision($file);
                $oldId = $base->binding($old)->identifier;
                $streamId = $base->binding($oldStream)->identifier;
                $deletions = [new ReleaseIndirectObject($oldId), new ReleaseIndirectObject($streamId)];
                $oldContainer = ($base->compressedObjects()[$oldId->objectNumber->value] ?? null)?->objectStreamNumber;
                $containerId = $oldContainer === null ? null : $base->binding(new ObjectIdentifier($oldContainer))->identifier;
                if ($containerId !== null) {
                    $deletions[] = new ReleaseIndirectObject($containerId);
                }
                $released = (new IncrementalWriter())->serialize(new IncrementalUpdate($base, new FileBody(), null, null, ...$deletions));
                $newStream = new StreamObject('Reused generation-one stream', new DictionaryType(new Entry(new NameType('Label'), new LiteralString('Generation-one stream dictionary'))), new FlateDecode());
                $newDictionary = new DictionaryType(new Entry(new NameType('Label'), new LiteralString('Reused generation-one dictionary')));
                $catalog = $released->binding($base->root->target())->dictionary;
                if ($catalog === null) {
                    throw new LogicException('The frozen catalog is a dictionary.');
                }
                $catalog = $catalog->with(new Entry(new NameType('CurrentString'), new IndirectReference($newStream)))
                    ->with(new Entry(new NameType('CurrentStream'), new IndirectReference($newDictionary)));
                $operations = [new ReuseObjectNumber($oldId->objectNumber, $newStream), new ReuseObjectNumber($streamId->objectNumber, $newDictionary)];
                $newContainerValue = $containerId === null ? null : new DictionaryType(new Entry(new NameType('Label'), new LiteralString('Reused former object-stream container')));
                if ($newContainerValue !== null && $containerId !== null) {
                    $operations[] = new ReuseObjectNumber($containerId->objectNumber, $newContainerValue);
                    $catalog = $catalog->with(new Entry(new NameType('CurrentContainer'), new IndirectReference($newContainerValue)));
                }
                $hole = null;
                foreach ($base->freeList->entries() as $entry) {
                    if ($entry->number() > 0 && $entry->generation->value === 0) {
                        $hole = new LiteralString('Unused hole generation zero');
                        $operations[] = new ReuseObjectNumber(new ObjectNumber($entry->number()), $hole);
                        $catalog = $catalog->with(new Entry(new NameType('UnusedHole'), new IndirectReference($hole)));
                        break;
                    }
                }
                $reused = (new IncrementalWriter())->serialize(new IncrementalUpdate($released, new FileBody(new IndirectObject($catalog, $base->root->identifier ?? throw new LogicException('The root has an identifier.'))), null, null, ...$operations));
                $deletions = [new ReleaseIndirectObject($reused->binding($newStream)->identifier), new ReleaseIndirectObject($reused->binding($newDictionary)->identifier)];
                if ($newContainerValue !== null) {
                    $deletions[] = new ReleaseIndirectObject($reused->binding($newContainerValue)->identifier);
                }
                if ($hole !== null) {
                    $deletions[] = new ReleaseIndirectObject($reused->binding($hole)->identifier);
                }
                $twice = (new IncrementalWriter())->serialize(new IncrementalUpdate($reused, new FileBody(), null, null, ...$deletions));
                $third = new LiteralString('Third live generation');
                $catalog = $twice->binding($catalog)->dictionary;
                if ($catalog === null) {
                    throw new LogicException('The effective catalog remains a dictionary.');
                }
                $catalog = $catalog->with(new Entry(new NameType('ThirdGeneration'), new IndirectReference($third)));
                $final = (new IncrementalWriter())->serialize(new IncrementalUpdate($twice, new FileBody(new IndirectObject($catalog, $twice->root->identifier ?? throw new LogicException('The root has an identifier.'))), objectOperations: new ReuseObjectNumber($oldId->objectNumber, $third)));
                $stem = $securityName . '-' . $format->name . '-' . (int) $packed . '-' . (int) $linearized;
                $files = [];
                foreach ([$base, $released, $reused, $twice, $final] as $index => $revision) {
                    $name = $stem . '-' . $index . '.pdf';
                    file_put_contents($directory . '/' . $name, $revision->bytes);
                    $files[] = lifecycleMetadata($revision, $name);
                }
                $manifest[] = ['files' => $files, 'password' => $encryption === null ? '' : 'user', 'linearized' => $linearized, 'format' => $format->name, 'original' => [$oldId->objectNumber->value, $streamId->objectNumber->value, ...($containerId === null ? [] : [$containerId->objectNumber->value])]];
            }
        }
    }
}
file_put_contents($directory . '/manifest-lifecycle.json', json_encode($manifest, JSON_THROW_ON_ERROR));
echo count($manifest), ' object lifecycle histories / ', count($manifest) * 5, ' files', PHP_EOL;
