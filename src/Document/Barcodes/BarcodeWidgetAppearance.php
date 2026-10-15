<?php

declare(strict_types=1);

namespace PDF\Document\Barcodes;

use PDF\Document\Forms\FormAppearanceStream;
use PDF\Document\Forms\WidgetAnnotation;
use PDF\Document\Forms\WidgetAppearance;
use PDF\Document\ResourceDictionary;
use PDF\Document\Resources\XObject;
use PDF\Graphics\ColourSpaces\DeviceColourSpace;
use PDF\Graphics\CoordinateSystems\Matrix;
use PDF\Graphics\XObjects\ImageXObject;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operators\ConcatenateMatrix;
use PDF\Syntax\ContentStreamsResources\Operators\InvokeXObject;
use PDF\Syntax\ContentStreamsResources\Operators\RestoreGraphicsState;
use PDF\Syntax\ContentStreamsResources\Operators\SaveGraphicsState;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;

/** Adobe supplement, physical page 47: one image fills the complete local widget rectangle. */
final readonly class BarcodeWidgetAppearance
{
    public FormAppearanceStream $form;
    public WidgetAppearance $appearance;
    public WidgetAnnotation $widget;

    public function __construct(public BarcodeEncodingRequest $request, public ImageXObject $image, Entry ...$entries)
    {
        $limits = $request->limits;
        if ($image->samples->dimensions->pixels() > $limits->maximumImagePixels || strlen($image->samples->bytes) > $limits->maximumImageBytes) {
            throw new \LengthException('The generated barcode image exceeds its pixel or sample-byte budget.');
        }
        if (!$image->colourSpace instanceof DeviceColourSpace || $image->mask !== null || $image->softMask !== null) {
            throw new \InvalidArgumentException('Native barcode appearances accept normalized opaque device-colour images.');
        }
        foreach ($image->dictionary()->entries() as $entry) {
            if (!in_array($entry->key->value, ['Type', 'Subtype', 'Width', 'Height', 'ColorSpace', 'BitsPerComponent', 'Decode', 'Interpolate', 'Intent'], true)) {
                throw new \InvalidArgumentException('A generated barcode image has no alternate, optional-content or ancillary resource graph.');
            }
        }
        foreach ($entries as $entry) {
            if ($entry->key->value === 'PMD') {
                throw new \InvalidArgumentException('The generated widget retains the exact PMD submitted to its encoder.');
            }
        }
        $name = new NameType('BarcodeImage');
        $width = $request->widget->width;
        $height = $request->widget->height;
        $this->form = new FormAppearanceStream(
            new ContentStreams(
                new SaveGraphicsState(),
                new ConcatenateMatrix(new Matrix(a: $width, d: $height)),
                new InvokeXObject($name),
                new RestoreGraphicsState(),
            ),
            new Rectangle(new NumericType(0), new NumericType(0), $width, $height),
            new ResourceDictionary(new XObject(new DictionaryType(new Entry($name, new IndirectReference($image))))),
        );
        $this->appearance = new WidgetAppearance($this->form);
        $this->widget = new WidgetAnnotation($request->widget->rectangle, $this->appearance, null, ...[new Entry(new NameType('PMD'), $request->widget->metadata), ...$entries]);
    }
}
