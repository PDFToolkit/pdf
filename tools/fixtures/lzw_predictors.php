<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Syntax\Object\IndirectObject\ObjectNumber;
use PDF\Syntax\Object\IndirectObject;
use PDF\Syntax\Object\StreamObject;

$directory = $argv[1] ?? throw new InvalidArgumentException('Pass an existing local output directory.');
if (!is_dir($directory) || !is_writable($directory)) {
    throw new InvalidArgumentException('The output directory must exist and be writable.');
}
$resources = new PDF\Document\ResourceDictionary();
$pages = new PDF\Syntax\DocumentStructure\PageTree\PageTreeNode();
$pages->addKid(new PDF\Syntax\DocumentStructure\PageTree\PageObject(new PDF\Syntax\DataStructure\Rectangle(new PDF\Syntax\Object\NumericType(0), new PDF\Syntax\Object\NumericType(0), new PDF\Syntax\Object\NumericType(100), new PDF\Syntax\Object\NumericType(100)), $resources));
$catalog = new PDF\Syntax\DocumentStructure\DocumentCatalog($pages);
$body = new PDF\Syntax\FileStructure\FileBody($catalog);
$cases = [];
$index = 100;
$bytes = '';
$state = 938101;
for ($i = 0; $i < 100000; ++$i) {
    $state = ($state * 1103515245 + 12345) & 0x7fffffff;
    $bytes .= chr(($state >> 16) & 255);
}
$plain = ['empty' => '', 'ISO' => '-----A---B', 'binary' => implode('', array_map(chr(...), range(0, 255))), 'repetition' => str_repeat('ABCABCABCABC', 1000), 'full-table' => $bytes];
foreach (range(250, 260) as $length) {
    $plain['end-boundary-' . $length] = substr($bytes, 0, $length);
}
foreach ([0, 1] as $early) {
    $encoder = new PDF\Syntax\Filters\LZWDecode(new PDF\Syntax\Filters\EarlyChange(new IntegerType($early)));
    foreach ($plain as $name => $data) {
        $stream = new StreamObject($data, null, $encoder);
        $body->addObject(new IndirectObject($stream, new ObjectIdentifier(new ObjectNumber($index))));
        $cases[] = ['object' => $index++, 'codec' => 'LZW', 'early' => $early, 'predictor' => 1, 'raw' => base64_encode($data), 'name' => $name];
    }
}
foreach (PDF\Syntax\Filters\PredictorBitsPerComponent::cases() as $depth) {
    foreach ([1, 2, 3, 5] as $colors) {
        foreach ([1, 3, 8] as $columns) {
            $layout = new PDF\Syntax\Filters\PredictorLayout(new IntegerType($colors), new IntegerType($columns), $depth);
            $data = substr($bytes, 0, $layout->bytesPerRow * 7);
            $preds = [new PDF\Syntax\Filters\TiffPredictor($layout), new PDF\Syntax\Filters\PngPredictor($layout)];
            foreach (PDF\Syntax\Filters\PngRowFilter::cases() as $filter) {
                $preds[] = new PDF\Syntax\Filters\PngPredictor($layout, new PDF\Syntax\Filters\FixedPngFilterSelection($filter));
            }
            foreach ($preds as $pred) {
                foreach (['Flate', 'LZW0', 'LZW1'] as $codec) {
                    $encoder = $codec === 'Flate' ? new PDF\Syntax\Filters\FlateDecode(6, $pred) : new PDF\Syntax\Filters\LZWDecode(new PDF\Syntax\Filters\EarlyChange(new IntegerType($codec === 'LZW0' ? 0 : 1)), $pred);
                    $stream = new StreamObject($data, null, $encoder);
                    $body->addObject(new IndirectObject($stream, new ObjectIdentifier(new ObjectNumber($index))));
                    $cases[] = ['object' => $index++, 'codec' => $codec, 'early' => $codec === 'LZW0' ? 0 : 1, 'predictor' => $pred->parameters()->get('Predictor')->value, 'colors' => $colors, 'columns' => $columns, 'bits' => $depth->value, 'raw' => base64_encode($data)];
                }
            }
        }
    }
}
$file = new PDF\FileStructure(new PDF\Syntax\FileStructure\FileHeader('1.7'), $body, new PDF\Syntax\Object\IndirectReference($catalog));
file_put_contents($directory . '/lzw-predictor-cases.pdf', $file->toPdf());
file_put_contents($directory . '/lzw-predictor-cases.json', json_encode($cases, JSON_THROW_ON_ERROR));
echo count($cases), ' native stream cases', PHP_EOL;
