<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources;

use PDF\Serialization\SerializationContext;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\StreamObject;

/** One instruction with direct, typed operands (ISO 32000-1, 7.8.2 and Annex A). */
abstract readonly class Operator implements \Stringable
{
    public const string SYMBOL = '';

    /** @var list<BasicType> */
    public array $operands;

    protected function __construct(BasicType ...$operands)
    {
        $this->operands = array_values($operands);
    }

    public function symbol(): string
    {
        return static::SYMBOL;
    }

    /** @return list<BasicType> */
    public function operands(): array
    {
        return $this->operands;
    }

    public function toPdf(SerializationContext $context): string
    {
        $tokens = [];
        foreach ($this->operands as $operand) {
            self::assertDirect($operand);
            $tokens[] = $context->serialize($operand);
        }
        $tokens[] = $this->symbol();

        return implode(' ', $tokens);
    }

    public function __toString(): string
    {
        return $this->toPdf(new SerializationContext());
    }

    /**
     * Indirect references and streams are prohibited as content operands.
     * @param array<int, true> $ancestors
     */
    protected static function assertDirect(BasicType $object, array $ancestors = []): void
    {
        if ($object instanceof IndirectReference || $object instanceof StreamObject) {
            throw new \InvalidArgumentException('Content-stream operands must be direct objects, and cannot contain streams.');
        }
        $id = spl_object_id($object);
        if (isset($ancestors[$id])) {
            throw new \InvalidArgumentException('A content-stream operand cannot contain a cyclic array or dictionary.');
        }
        $ancestors[$id] = true;
        if ($object instanceof ArrayType) {
            foreach ($object->items as $item) {
                self::assertDirect($item, $ancestors);
            }
        } elseif ($object instanceof DictionaryType) {
            foreach ($object->entries() as $entry) {
                self::assertDirect($entry->value, $ancestors);
            }
        }
    }
}
