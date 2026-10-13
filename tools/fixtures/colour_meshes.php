<?php

declare(strict_types=1);

use PDF\Functions\NumberArray;
use PDF\Graphics\Patterns\Shadings\CoonsPatch;
use PDF\Graphics\Patterns\Shadings\DecodeInterval;
use PDF\Graphics\Patterns\Shadings\FreeFormMesh;
use PDF\Graphics\Patterns\Shadings\FreeFormTriangle;
use PDF\Graphics\Patterns\Shadings\LatticeMesh;
use PDF\Graphics\Patterns\Shadings\MeshBitDepths;
use PDF\Graphics\Patterns\Shadings\MeshDecode;
use PDF\Graphics\Patterns\Shadings\MeshFormat;
use PDF\Graphics\Patterns\Shadings\MeshVertex;
use PDF\Graphics\Patterns\Shadings\PatchColours;
use PDF\Graphics\Patterns\Shadings\PatchContinuation;
use PDF\Graphics\Patterns\Shadings\PatchControlPoints;
use PDF\Graphics\Patterns\Shadings\PatchMesh;
use PDF\Graphics\Patterns\Shadings\Point;
use PDF\Graphics\Patterns\Shadings\TensorProductPatch;
use PDF\Graphics\Patterns\Shadings\TriangleContinuation;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NumericType;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$n = static fn (int $value): NumericType => new NumericType($value);
$point = static fn (int $x, int $y): Point => new Point($n($x), $n($y));
$sample = static fn (int $value): NumberArray => new NumberArray($n($value));
$vertex = static fn (int $x, int $y, int $colour): MeshVertex => new MeshVertex($point($x, $y), $sample($colour));
$controls = static fn (int $count): PatchControlPoints => new PatchControlPoints(...array_fill(0, $count, $point(0, 0)));
$unit = new DecodeInterval($n(0), $n(1));
$format = new MeshFormat(new MeshDecode($unit, $unit, $unit), new MeshBitDepths(new IntegerType(1), new IntegerType(1), new IntegerType(2)));
$corners = new PatchColours($sample(1), $sample(0), $sample(1), $sample(0));
$triangle = new FreeFormMesh(new FreeFormTriangle($vertex(0, 0, 1), $vertex(1, 0, 0), $vertex(0, 1, 1)), new TriangleContinuation(new IntegerType(1), $vertex(1, 1, 0)), new TriangleContinuation(new IntegerType(2), $vertex(0, 0, 1)));
$coons = new CoonsPatch($controls(12), $corners);
$tensor = new TensorProductPatch($controls(16), $corners);
$result = [
    'triangle' => bin2hex($triangle->bytes($format)),
    'lattice' => bin2hex((new LatticeMesh(new IntegerType(2), $vertex(0, 0, 1), $vertex(1, 0, 0), $vertex(0, 1, 1), $vertex(1, 1, 0)))->bytes($format)),
    'coons' => bin2hex((new PatchMesh($coons, new PatchContinuation(new IntegerType(1), $controls(8), $sample(1), $sample(0))))->bytes($format)),
    'tensor' => bin2hex((new PatchMesh($tensor, new PatchContinuation(new IntegerType(1), $controls(12), $sample(1), $sample(0))))->bytes($format)),
    'coons_all_edges' => bin2hex((new PatchMesh($coons, new PatchContinuation(new IntegerType(1), $controls(8), $sample(1), $sample(0)), new PatchContinuation(new IntegerType(2), $controls(8), $sample(1), $sample(0)), new PatchContinuation(new IntegerType(3), $controls(8), $sample(1), $sample(0))))->bytes($format)),
    'tensor_all_edges' => bin2hex((new PatchMesh($tensor, new PatchContinuation(new IntegerType(1), $controls(12), $sample(1), $sample(0)), new PatchContinuation(new IntegerType(2), $controls(12), $sample(1), $sample(0)), new PatchContinuation(new IntegerType(3), $controls(12), $sample(1), $sample(0))))->bytes($format)),
];
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL;
