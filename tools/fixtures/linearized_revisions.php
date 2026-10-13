<?php

declare(strict_types=1);

require __DIR__ . '/linearization.php';

use PDF\Serialization\IncrementalWriter;
use PDF\Serialization\LinearizedWriter;
use PDF\Syntax\FileStructure\CrossReferenceFormat;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\IncrementalUpdate;
use PDF\Syntax\Filters\FlateDecode;
use PDF\Syntax\Linearization\HintStreamSplit;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringObject\LiteralString;

$manifest = [];
foreach (linearizationSecurityCases() as [$securityName, $encryption]) {
    foreach (CrossReferenceFormat::cases() as $format) {
        foreach ($format === CrossReferenceFormat::Table ? [false] : [false, true] as $packed) {
            foreach ([null, 1] as $boundary) {
                [$file, $leaves] = linearizationFixture($format, $packed, 0);
                $base = (new LinearizedWriter(hintSplit: $boundary === null ? null : new HintStreamSplit(new IntegerType($boundary)), encryption: $encryption))->revision($file);
                $contents = $leaves[1]->get('Contents');
                $info = $base->trailerEntries->get('Info');
                if (!$contents instanceof IndirectReference || !$info instanceof IndirectReference || !$info->target() instanceof DictionaryType) {
                    throw new LogicException('The fixture requires indirect page contents and document information.');
                }
                $leaf = $base->binding($leaves[1])->snapshot;
                if (!$leaf instanceof DictionaryType) {
                    throw new LogicException('The effective leaf is a frozen dictionary.');
                }
                $updatedLeaf = $leaf->with(new Entry(new NameType('PrivateData'), new LiteralString('Updated page 1')));
                $updatedContents = new StreamObject('BT /F1 12 Tf 20 300 Td (Updated page 1) Tj ET', filters: new FlateDecode());
                $updatedInfo = $info->target()->with(new Entry(new NameType('Title'), new LiteralString('Updated title')));
                $one = (new IncrementalWriter())->serialize(new IncrementalUpdate($base, new FileBody(
                    new IndirectObject($updatedLeaf, $base->binding($leaves[1])->identifier),
                    new IndirectObject($updatedContents, $base->binding($contents->target())->identifier),
                    new IndirectObject($updatedInfo, $base->binding($info->target())->identifier),
                )));
                $catalog = $base->root->target();
                if (!$catalog instanceof DictionaryType) {
                    throw new LogicException('The effective root is a catalog.');
                }
                $updatedCatalog = $catalog->with(new Entry(new NameType('PrivateEpoch'), new IntegerType(2)));
                $two = (new IncrementalWriter())->serialize(new IncrementalUpdate($one, new FileBody(new IndirectObject($updatedCatalog, $one->binding($catalog)->identifier))));
                $stem = $securityName . '-' . $format->name . '-' . (int) $packed . '-' . ($boundary ?? 'primary');
                $files = [];
                foreach ([$base, $one, $two] as $index => $revision) {
                    $name = $stem . '-' . $index . '.pdf';
                    file_put_contents($directory . '/' . $name, $revision->bytes);
                    $spans = [];
                    foreach ($revision->spans() as $span) {
                        $spans[] = ['number' => $span->identifier->objectNumber->value, 'generation' => $span->identifier->generationNumber->value, 'offset' => $span->offset->value, 'length' => $span->length, 'sha256' => hash('sha256', substr($revision->bytes, $span->offset->value, $span->length))];
                    }
                    $files[] = ['file' => $name, 'length' => strlen($revision->bytes), 'xref' => $revision->crossReferenceOffset->value, 'size' => $revision->size->value, 'spans' => $spans];
                }
                $manifest[] = ['files' => $files, 'password' => $encryption === null ? '' : 'user', 'metadata' => $encryption?->metadata()->value ?? true];
            }
        }
    }
}
file_put_contents($directory . '/manifest-revisions.json', json_encode($manifest, JSON_THROW_ON_ERROR));
echo count($manifest), ' linearized revision histories / ', count($manifest) * 3, ' files', PHP_EOL;
