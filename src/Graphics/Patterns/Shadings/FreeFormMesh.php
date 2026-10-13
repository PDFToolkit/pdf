<?php

declare(strict_types=1);

namespace PDF\Graphics\Patterns\Shadings;

/** A type 4 stream contains complete triangles and valid previous-edge connections (pages 198–200). */
final readonly class FreeFormMesh
{
    /** @var non-empty-list<FreeFormTriangle|TriangleContinuation> */
    public array $records;

    public function __construct(FreeFormTriangle|TriangleContinuation ...$records)
    {
        $records = array_values($records);
        if ($records === [] || !$records[0] instanceof FreeFormTriangle) {
            throw new \InvalidArgumentException('A free-form mesh starts with one complete independent triangle.');
        }
        $this->records = $records;
    }

    public function bytes(MeshFormat $format): string
    {
        $writer = new MeshBitWriter($format->maximumBytes);
        foreach ($this->records as $record) {
            if ($record instanceof FreeFormTriangle) {
                foreach ([$record->a, $record->b, $record->c] as $vertex) {
                    $format->writeVertex($writer, $vertex, 0);
                }
            } else {
                $format->writeVertex($writer, $record->vertex, $record->edge->integer());
            }
        }
        return $writer->bytes();
    }

    /** @return non-empty-list<FreeFormTriangle> */
    public function triangles(): array
    {
        $triangles = [];
        $previous = null;
        foreach ($this->records as $record) {
            if ($record instanceof FreeFormTriangle) {
                $previous = $record;
            } else {
                if ($previous === null) {
                    throw new \LogicException('A validated mesh must have a preceding triangle.');
                }
                $previous = new FreeFormTriangle($record->edge->integer() === 1 ? $previous->b : $previous->a, $previous->c, $record->vertex);
            }
            $triangles[] = $previous;
        }
        return $triangles;
    }
}
