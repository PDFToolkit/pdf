<?php

declare(strict_types=1);

namespace PDF\Graphics\GraphicsState;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\NameType;

/** Ordered blend-mode fallback names, including explicitly supplied extension modes. */
final readonly class BlendModes
{
    /** @var non-empty-list<NameType> */
    public array $names;

    public function __construct(BlendMode|NameType ...$modes)
    {
        if ($modes === []) {
            throw new \InvalidArgumentException('A blend selection requires at least one named mode.');
        }
        $this->names = array_map(static fn (BlendMode|NameType $mode): NameType => $mode instanceof BlendMode ? new NameType($mode->value) : $mode, array_values($modes));
    }

    public function value(): NameType|ArrayType
    {
        return count($this->names) === 1 ? $this->names[0] : new ArrayType(...$this->names);
    }

    public function standard(): BlendMode
    {
        foreach ($this->names as $name) {
            $mode = BlendMode::tryFrom($name->value);
            if ($mode !== null) {
                return $mode;
            }
        }
        return BlendMode::Normal;
    }
}
