<?php

declare(strict_types=1);

namespace PDF\Graphics\Patterns\Shadings;

use PDF\Syntax\Object\IntegerType;

/** Ordered, complete rows with their source-defined triangle topology (type 5, pages 201–202). */
final readonly class LatticeMesh
{
    /** @var non-empty-list<MeshVertex> */
    public array $vertices;

    public function __construct(public IntegerType $verticesPerRow, MeshVertex ...$vertices)
    {
        $width = $verticesPerRow->integer();
        if ($width < 2 || count($vertices) < 2 * $width || count($vertices) % $width !== 0) {
            throw new \InvalidArgumentException('A lattice needs complete rows of at least two vertices and at least two rows.');
        }
        $this->vertices = array_values($vertices);
    }

    public function bytes(MeshFormat $format): string
    {
        $writer = new MeshBitWriter($format->maximumBytes);
        foreach ($this->vertices as $vertex) {
            $format->writeVertex($writer, $vertex);
        }
        return $writer->bytes();
    }

    /** @return non-empty-list<FreeFormTriangle> */
    public function triangles(): array
    {
        $result = [];
        $width = $this->verticesPerRow->integer();
        $rows = intdiv(count($this->vertices), $width);
        for ($row = 0; $row < $rows - 1; ++$row) {
            for ($column = 0; $column < $width - 1; ++$column) {
                $offset = $row * $width + $column;
                $result[] = new FreeFormTriangle($this->vertices[$offset], $this->vertices[$offset + 1], $this->vertices[$offset + $width]);
                $result[] = new FreeFormTriangle($this->vertices[$offset + 1], $this->vertices[$offset + $width], $this->vertices[$offset + $width + 1]);
            }
        }
        if ($result === []) {
            throw new \LogicException('A complete two-row lattice must produce triangles.');
        }
        return $result;
    }
}
