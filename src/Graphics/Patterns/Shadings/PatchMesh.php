<?php

declare(strict_types=1);

namespace PDF\Graphics\Patterns\Shadings;

/** Complete Coons/tensor patches and explicit compressed edge-reuse records (types 6–7). */
final readonly class PatchMesh
{
    /** @var non-empty-list<CoonsPatch|TensorProductPatch|PatchContinuation> */
    public array $records;
    public int $type;

    public function __construct(CoonsPatch|TensorProductPatch|PatchContinuation ...$records)
    {
        $records = array_values($records);
        if ($records === [] || $records[0] instanceof PatchContinuation) {
            throw new \InvalidArgumentException('A patch mesh begins with a complete patch.');
        }
        $this->type = $records[0] instanceof CoonsPatch ? 6 : 7;
        $count = $this->type === 6 ? 12 : 16;
        foreach ($records as $record) {
            if (($record instanceof CoonsPatch && $this->type !== 6) || ($record instanceof TensorProductPatch && $this->type !== 7) || ($record instanceof PatchContinuation && count($record->newControls->points) !== $count - 4)) {
                throw new \InvalidArgumentException('Patch records must match one mesh type and omit exactly the four reused controls.');
            }
        }
        $this->records = $records;
        $this->patches();
    }

    public function bytes(MeshFormat $format): string
    {
        $writer = new MeshBitWriter($format->maximumBytes);
        foreach ($this->records as $record) {
            $continuation = $record instanceof PatchContinuation;
            $writer->write($continuation ? $record->edge->integer() : 0, $format->bits->flag->integer());
            $controls = $continuation ? $record->newControls : $record->controls;
            foreach ($controls->points as $point) {
                $format->writePoint($writer, $point);
            }
            $colours = $continuation ? [$record->thirdColour, $record->fourthColour] : $record->colours->corners();
            foreach ($colours as $colour) {
                $format->writeComponents($writer, $colour);
            }
            // Patch sets are continuous bit records: there is no per-vertex alignment
            // between a patch's controls, its corner data, or the next patch (8.7.4.5.7–8).
        }
        return $writer->bytes();
    }

    /** @return non-empty-list<CoonsPatch|TensorProductPatch> */
    public function patches(): array
    {
        $result = [];
        $previous = null;
        foreach ($this->records as $record) {
            if (!$record instanceof PatchContinuation) {
                $previous = $record;
            } else {
                if ($previous === null) {
                    throw new \LogicException('A validated patch continuation needs a previous patch.');
                }
                $indices = match ($record->edge->integer()) {
                    1 => [3, 4, 5, 6],
                    2 => [6, 7, 8, 9],
                    default => [9, 10, 11, 0],
                };
                $controls = new PatchControlPoints(...[...array_map(static fn (int $index): Point => $previous->controls->points[$index], $indices), ...$record->newControls->points]);
                $corners = $previous->colours->corners();
                $colours = new PatchColours($corners[$record->edge->integer()], $corners[($record->edge->integer() + 1) % 4], $record->thirdColour, $record->fourthColour);
                $previous = $this->type === 6 ? new CoonsPatch($controls, $colours) : new TensorProductPatch($controls, $colours);
            }
            $result[] = $previous;
        }
        return $result;
    }
}
