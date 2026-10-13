<?php

declare(strict_types=1);

use PDF\Document\Forms\Fdf\FdfCatalog;
use PDF\Document\Forms\Fdf\FdfCrossReference;
use PDF\Document\Forms\Fdf\FdfExporter;
use PDF\Document\Forms\Fdf\FdfWriter;
use PDF\Syntax\Object\NameType;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$directory = $argv[1] ?? throw new InvalidArgumentException('Supply a fixture output directory.');
if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
    throw new RuntimeException('Cannot create fixture output directory.');
}
$argv[1] = $directory . '/acroform-fields.pdf';
require dirname(__DIR__, 2) . '/examples/acroform-fields.php';

// The selection is explicit. Exporting data never submits it or reads a local file.
$data = (new FdfExporter($tree))->export($name, $countries, $consent, $delivery);
$catalog = new FdfCatalog($data, new NameType('1.7'));
foreach (['minimal' => FdfCrossReference::Omitted, 'traditional' => FdfCrossReference::Traditional] as $suffix => $format) {
    if (file_put_contents($directory . '/acroform-' . $suffix . '.fdf', (new FdfWriter($format))->serialize($catalog)) === false) {
        throw new RuntimeException('Cannot write FDF fixture.');
    }
}
