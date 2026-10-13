<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operator;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\StreamObject;

/** Page Contents arrays are one logical stream, including marked-content scopes (14.6.1, page 561). */
final readonly class PageContentSequence
{
    /** @var non-empty-list<ContentStreams> */
    private array $parts;
    private ContentStreams $combined;
    /** @var non-empty-list<StreamObject> */
    private array $streams;

    public function __construct(ContentStreams ...$parts)
    {
        if ($parts === []) {
            throw new \InvalidArgumentException('A page-content sequence requires at least one explicit part.');
        }
        $this->parts = array_values($parts);
        $operators = [];
        foreach ($parts as $part) {
            array_push($operators, ...$part->operators);
        }
        $this->combined = new ContentStreams(...$operators);
        $this->combined->validate();
        $streams = [];
        foreach ($this->parts as $index => $part) {
            $fragment = new PageContentFragment(...$part->operators);
            $fragment->attachTo($this, new IntegerType($index));
            $streams[] = new StreamObject($fragment);
        }
        $this->streams = $streams;
    }

    public function contents(): ArrayType
    {
        return new ArrayType(...array_map(static fn (StreamObject $stream): IndirectReference => new IndirectReference($stream), $this->streams));
    }

    /** @return non-empty-list<StreamObject> */
    public function streams(): array
    {
        return $this->streams;
    }

    /**
     * @internal
     * @return list<Operator>
     */
    public function fragmentOperators(IntegerType $number): array
    {
        return ($this->parts[$number->integer()] ?? throw new \OutOfBoundsException('No such page content part.'))->operators;
    }

    public function validate(): void
    {
        $this->combined->validate();
    }
}
