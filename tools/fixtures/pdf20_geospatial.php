<?php

declare(strict_types=1);

use PDF\FileStructure;
use PDF\Serialization\FileWriter;
use PDF\Serialization\IncrementalWriter;
use PDF\Serialization\LinearizedWriter;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\FileStructure\CrossReferenceFormat;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\IncrementalUpdate;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IndirectObject;
use PDF\Syntax\Object\NameType;

$targetDirectory = $argv[1] ?? throw new InvalidArgumentException('Supply the generated fixture directory.');
if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0700, true)) {
    throw new RuntimeException('Cannot create geospatial fixture directory.');
}
$argv = [__FILE__, $targetDirectory . '/example.pdf'];
require dirname(__DIR__, 2) . '/examples/pdf20-geospatial.php';
$manifest = [];
foreach (CrossReferenceFormat::cases() as $layout) {
    foreach ([false, true] as $linearized) {
        $variant = new FileStructure($file->header, $file->body, crossReferenceFormat: $layout);
        $revision = ($linearized ? new LinearizedWriter() : new FileWriter())->revision($variant);
        $root = $revision->binding($catalog);
        $changed = $root->dictionary->with(new Entry(new NameType('Lang'), new TextString('en')));
        $following = (new IncrementalWriter())->serialize(new IncrementalUpdate($revision, new FileBody(new IndirectObject($changed, $root->identifier))));
        $prefix = strtolower($layout->name) . ($linearized ? '-linearized' : '');
        foreach ([['initial', $revision], ['incremental', $following]] as [$stage, $outputRevision]) {
            $name = $prefix . '-' . $stage . '.pdf';
            $outputRevision->writeTo($targetDirectory . '/' . $name);
            $manifest[] = ['file' => $name, 'linearized' => $linearized, 'baseLength' => strlen($revision->bytes), 'initial' => $prefix . '-initial.pdf', 'incremental' => $stage === 'incremental'];
        }
    }
}
file_put_contents($targetDirectory . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
echo count($manifest) . " geospatial PDFs generated.\n";
