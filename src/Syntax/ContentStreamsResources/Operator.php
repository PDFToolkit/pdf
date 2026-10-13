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

    /** Indirect references and streams are prohibited as content operands. */
    protected static function assertDirect(BasicType $object): void
    {
        /** @var \SplObjectStorage<BasicType, null> $active */
        $active = new \SplObjectStorage();
        /** @var \SplObjectStorage<BasicType, null> $completed */
        $completed = new \SplObjectStorage();
        self::visitDirect($object, $active, $completed);
    }

    /**
     * @param \SplObjectStorage<BasicType, null> $active
     * @param \SplObjectStorage<BasicType, null> $completed
     */
    private static function visitDirect(BasicType $value, \SplObjectStorage $active, \SplObjectStorage $completed): void
    {
        if ($value instanceof IndirectReference || $value instanceof StreamObject) {
            throw new \InvalidArgumentException('Content-stream operands must be direct objects, and cannot contain streams.');
        }
        if ($active->offsetExists($value)) {
            throw new \InvalidArgumentException('A content-stream operand cannot contain a cyclic array or dictionary.');
        }
        if ($completed->offsetExists($value)) {
            return;
        }
        $active->offsetSet($value);
        try {
            if ($value instanceof ArrayType) {
                foreach ($value->items as $item) {
                    self::visitDirect($item, $active, $completed);
                }
            } elseif ($value instanceof DictionaryType) {
                foreach ($value->entries() as $entry) {
                    self::visitDirect($entry->value, $active, $completed);
                }
            }
        } finally {
            $active->offsetUnset($value);
        }
        $completed->offsetSet($value);
    }
}
