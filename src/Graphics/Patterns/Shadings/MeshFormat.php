<?php

declare(strict_types=1);

namespace PDF\Graphics\Patterns\Shadings;

use PDF\Functions\Domain;
use PDF\Functions\NumberArray;
use PDF\Graphics\ColourSpaces\ColourSpace;
use PDF\Graphics\ColourSpaces\ColourSpaceFamily;

/** Couples bit widths, Decode and function/sample dimensionality without global encoding state. */
final readonly class MeshFormat
{
    public function __construct(public MeshDecode $decode, public MeshBitDepths $bits = new MeshBitDepths(), public int $maximumBytes = 67108864)
    {
        if ($maximumBytes < 1) {
            throw new \InvalidArgumentException('The mesh output limit must be positive.');
        }
    }

    public function validate(ColourSpace $space, ?ShadingFunctions $functions): void
    {
        if ($space->family() === ColourSpaceFamily::Pattern || ($space->family() === ColourSpaceFamily::Indexed && $functions !== null)) {
            throw new \InvalidArgumentException('A mesh cannot use Pattern; Indexed meshes cannot specify colour functions.');
        }
        if (count($this->decode->components) !== ($functions === null ? $space->componentCount() : 1)) {
            throw new \InvalidArgumentException('Mesh Decode needs every colour component, or exactly one interval when Function is present.');
        }
        $functions?->validate(1, $space->componentCount(), new Domain($this->decode->components[0]->domain()));
    }

    public function writePoint(MeshBitWriter $writer, Point $point): void
    {
        $writer->write($this->decode->x->encode($point->x, $this->bits->coordinate->integer()), $this->bits->coordinate->integer());
        $writer->write($this->decode->y->encode($point->y, $this->bits->coordinate->integer()), $this->bits->coordinate->integer());
    }

    public function writeComponents(MeshBitWriter $writer, NumberArray $components): void
    {
        if (count($components) !== count($this->decode->components)) {
            throw new \InvalidArgumentException('Each mesh vertex/corner must provide exactly the number of samples specified by Decode.');
        }
        foreach ($components->numbers as $index => $number) {
            $writer->write($this->decode->components[$index]->encode($number, $this->bits->component->integer()), $this->bits->component->integer());
        }
    }

    public function writeVertex(MeshBitWriter $writer, MeshVertex $vertex, ?int $flag = null): void
    {
        if ($flag !== null) {
            $writer->write($flag, $this->bits->flag->integer());
        }
        $this->writePoint($writer, $vertex->point);
        $this->writeComponents($writer, $vertex->components);
        // Triangle-mesh vertices, unlike patch point sets, each occupy whole bytes (page 200).
        $writer->align();
    }
}
