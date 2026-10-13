<?php

declare(strict_types=1);

namespace PDF\Document\Navigation;

use PDF\Document\DocumentGraphComponent;
use PDF\Document\DocumentGraphContext;
use PDF\Document\Destinations\Destination;
use PDF\Document\VersionedComponent;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\NumericType;

/** The existing eight explicit destinations, with local ownership and actual fitting math. */
final class LocalDestination extends Destination implements DocumentGraphComponent, VersionedComponent
{
    public readonly PageObject $page;
    public readonly string $mode;

    public function __construct(Destination $destination)
    {
        $parameters = new ExplicitDestinationParameters($destination);
        $this->page = $parameters->page();
        $this->mode = $parameters->fit->value;
        parent::__construct($parameters->reference, $parameters->fit, ...$parameters->coordinates);
    }

    public function validateCatalog(DictionaryType $catalog): void
    {
        (new CatalogPages($catalog))->index($this->page);
    }

    public function validateGraph(DocumentGraphContext $graph): void
    {
        if (!$graph->isCurrent($this) || !$graph->isReachable($this)) {
            return;
        }
        $effective = $graph->resolve($this);
        if (!$effective instanceof ArrayType) {
            throw new \InvalidArgumentException('An effective local destination must remain an array.');
        }
        // Whole-document page ownership is checked once by NavigationGraphValidation.
        new ExplicitDestinationParameters($effective);
    }

    public function validateVersion(EffectivePdfVersion $version): void
    {
        if (in_array($this->mode, ['FitB', 'FitBH', 'FitBV'], true)) {
            $version->require(PdfVersion::PDF11, 'Bounding-box destinations');
        }
    }

    /** Table151 math; renderer rotation and physical screen transforms are caller inputs. */
    public function resolve(DestinationViewport $viewport, DestinationGeometry $geometry): ViewPosition
    {
        if ($this->mode === 'XYZ') {
            $left = $this->coordinate(2) ?? ($viewport->current ?? throw new \LogicException('Null XYZ left needs the current view.'))->left;
            $top = $this->coordinate(3) ?? ($viewport->current ?? throw new \LogicException('Null XYZ top needs the current view.'))->top;
            $zoom = $this->coordinate(4);
            if ($zoom === null || $zoom->value == 0) {
                $zoom = ($viewport->current ?? throw new \LogicException('Null or zero XYZ zoom needs the current view.'))->zoom;
            }
            return new ViewPosition($left, $top, $zoom);
        }
        $box = in_array($this->mode, ['FitB', 'FitBH', 'FitBV'], true) ? $geometry->boundingBox() : $geometry->pageBox;
        if ($this->mode === 'FitR') {
            $box = new Rectangle($this->required(2), $this->required(3), $this->required(4), $this->required(5));
        }
        $left = min($box->lowerLeftX->value, $box->upperRightX->value);
        $right = max($box->lowerLeftX->value, $box->upperRightX->value);
        $bottom = min($box->lowerLeftY->value, $box->upperRightY->value);
        $top = max($box->lowerLeftY->value, $box->upperRightY->value);
        $width = $right - $left;
        $height = $top - $bottom;
        if ($width <= 0 || $height <= 0 || !is_finite($width) || !is_finite($height)) {
            throw new \OutOfRangeException('Fitting requires positive, representable rectangle dimensions.');
        }
        if ($this->mode === 'FitH' || $this->mode === 'FitBH') {
            $zoom = $viewport->width->value / $width;
            $top = ($this->coordinate(2) ?? ($viewport->current ?? throw new \LogicException('Null FitH top needs the current view.'))->top)->value;
        } elseif ($this->mode === 'FitV' || $this->mode === 'FitBV') {
            $zoom = $viewport->height->value / $height;
            $left = ($this->coordinate(2) ?? ($viewport->current ?? throw new \LogicException('Null FitV left needs the current view.'))->left)->value;
        } else {
            $zoom = min($viewport->width->value / $width, $viewport->height->value / $height);
            if ($zoom <= 0 || !is_finite($zoom)) {
                throw new \OutOfRangeException('The fitting magnification is not representable.');
            }
            $left -= ($viewport->width->value / $zoom - $width) / 2;
            $top += ($viewport->height->value / $zoom - $height) / 2;
        }
        return new ViewPosition(new NumericType($left), new NumericType($top), new NumericType($zoom));
    }

    private function coordinate(int $index): ?NumericType
    {
        $value = $this->items[$index];
        return $value instanceof NumericType ? $value : null;
    }

    private function required(int $index): NumericType
    {
        return $this->coordinate($index) ?? throw new \LogicException('A rectangle coordinate is missing.');
    }
}
