<?php

declare(strict_types=1);

namespace PDF\Document\Forms\Fdf;

use PDF\Document\Forms\PartialFieldName;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;

/** @internal One export operation's hierarchy; never shared between documents. */
final class FdfExportNode
{
    /** @var array<string, self> */
    private array $children = [];
    private ?BasicType $value = null;

    public function __construct(private readonly string $name)
    {
    }

    /** @param non-empty-list<string> $path */
    public function insert(array $path, ?BasicType $value): void
    {
        $name = array_shift($path);
        $child = $this->children[$name] ??= new self($name);
        if ($path === []) {
            $child->value = $value;
        } else {
            $child->insert($path, $value);
        }
    }

    /** @return list<FdfField> */
    public function fields(): array
    {
        return array_values(array_map(static fn (self $child) => $child->field(), $this->children));
    }

    private function field(): FdfField
    {
        $children = $this->fields();
        return new FdfField(new PartialFieldName(new TextString($this->name)), $this->value, $children === [] ? null : new ArrayType(...$children));
    }
}
