<?php

declare(strict_types=1);

use PDF\Document\Images\DeviceColorSpace;
use PDF\Document\Images\ImageDimensions;
use PDF\Document\Images\SampleLayout;
use PDF\Graphics\ColourSpaces\ColourComponents;
use PDF\Graphics\ColourSpaces\ColourSpaceFamily;
use PDF\Graphics\ColourSpaces\DeviceColourSpace;
use PDF\Graphics\GraphicsState\BlendFunction;
use PDF\Graphics\GraphicsState\BlendMode;
use PDF\Graphics\GraphicsState\BlendingColourSpace;
use PDF\Graphics\OptionalContent as OC;
use PDF\Graphics\XObjects\SamplePacker;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\Object\NumericType;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$n = static fn (int|float $value): NumericType => new NumericType($value);
$packer = new SamplePacker();
$imageHex = [];
foreach ([1, 2, 4, 8, 16] as $bits) {
    $maximum = (1 << $bits) - 1;
    $imageHex[$bits] = bin2hex($packer->pack(new ImageDimensions(3, 2), new SampleLayout(DeviceColorSpace::Gray, $bits), [0, $maximum, 0, $maximum, 0, $maximum])->bytes);
}
$blendCases = [];
$rgb = new DeviceColourSpace(ColourSpaceFamily::DeviceRGB);
$cmyk = new DeviceColourSpace(ColourSpaceFamily::DeviceCMYK);
foreach (['rgb' => $rgb, 'cmyk' => $cmyk] as $label => $space) {
    $backdrop = $label === 'rgb' ? [0.2, 0.4, 0.6] : [0.2, 0.4, 0.6, 0.25];
    $source = $label === 'rgb' ? [0.8, 0.3, 0.1] : [0.8, 0.3, 0.1, 0.75];
    foreach (BlendMode::cases() as $mode) {
        $function = new BlendFunction($mode, new BlendingColourSpace($space));
        $b = new ColourComponents($space, ...array_map($n, $backdrop));
        $s = new ColourComponents($space, ...array_map($n, $source));
        $blended = $function->blend($b, $s);
        $composite = $function->composite($b, $n(0.4), $s, $n(0.6));
        if ($composite->colour === null) {
            throw new LogicException('Positive input opacities must yield a defined composite colour.');
        }
        $blendCases[] = ['space' => $label, 'mode' => $mode->value, 'backdrop' => $backdrop, 'source' => $source, 'blend' => array_map(static fn (NumericType $value): int|float => $value->value, $blended->numbers), 'composite' => array_map(static fn (NumericType $value): int|float => $value->value, $composite->colour->numbers), 'alpha' => $composite->alpha->value];
    }
}
$a = new OC\OptionalContentGroup(new TextString('A'));
$b = new OC\OptionalContentGroup(new TextString('B'));
$groups = new OC\GroupCollection($a, $b);
$states = new OC\GroupStates($groups);
$policies = [];
foreach ([[false, false], [false, true], [true, false], [true, true]] as [$onA, $onB]) {
    $states->set($a, $onA);
    $states->set($b, $onB);
    foreach (OC\VisibilityPolicy::cases() as $policy) {
        $policies[] = ['a' => $onA, 'b' => $onB, 'policy' => $policy->value, 'visible' => (new OC\OptionalContentMembership($groups, $policy))->visible($states)];
    }
}
echo json_encode(['image_hex' => $imageHex, 'blend_cases' => $blendCases, 'policies' => $policies], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL;
