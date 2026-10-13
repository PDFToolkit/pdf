<?php

declare(strict_types=1);

namespace PDF\Syntax\FileStructure;

use PDF\Serialization\SerializationContext;
use PDF\Syntax\Filters\EncodedData;
use PDF\Syntax\Filters\Filter;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;

/**
 * A real object stream with byte offsets and generation-zero members (7.5.7).
 *
 * The writer must reserve the member numbers before assigning other numbers,
 * and must exclude these members from standalone indirect-object output.
 * Encryption dictionaries and indirect stream-length objects must be excluded
 * by the document writer, which knows those relationships.
 */
final class ObjectStream extends StreamObject
{
    /** @var list<ObjectStreamEntry> */
    private readonly array $members;

    public function __construct(ObjectStreamEntry|Filter ...$parts)
    {
        $members = [];
        $filters = [];
        $numbers = [];
        $identities = [];
        foreach ($parts as $part) {
            if ($part instanceof Filter) {
                if ($part instanceof EncodedData) {
                    throw new \InvalidArgumentException('Object-stream data are generated and cannot be marked as pre-encoded.');
                }
                $filters[] = $part;
                continue;
            }
            $number = $part->objectNumber->value;
            $id = spl_object_id($part->value);
            if (isset($numbers[$number]) || isset($identities[$id])) {
                throw new \InvalidArgumentException('Object-stream member numbers and object identities must be unique.');
            }
            $numbers[$number] = true;
            $identities[$id] = true;
            $members[] = $part;
        }
        $this->members = $members;
        parent::__construct('', new DictionaryType(
            new Entry(new NameType('Type'), new NameType('ObjStm')),
            new Entry(new NameType('N'), new IntegerType(count($members))),
        ), ...$filters);
    }

    /** @return list<ObjectStreamEntry> */
    public function embeddedEntries(): array
    {
        return $this->members;
    }

    /** Replace decoding filters, preserving the same object-valued members. */
    #[\NoDiscard]
    public function withFilters(Filter ...$filters): self
    {
        return new self(...[...$this->members, ...$filters]);
    }

    /** The unencoded header and object bodies, with offsets measured in bytes. */
    public function decodedBytes(SerializationContext $context): string
    {
        return $this->build($context)[0];
    }

    public function dictionaryFor(SerializationContext $context): DictionaryType
    {
        return $this->dictionary()->with(new Entry(new NameType('First'), new IntegerType($this->build($context)[1])));
    }

    public function bytes(SerializationContext $context): string
    {
        $bytes = $this->decodedBytes($context);
        foreach (array_reverse($this->filters) as $filter) {
            $bytes = $filter->encode($bytes);
        }
        return $bytes;
    }

    public function toPdf(SerializationContext $context): string
    {
        [$bytes, $first] = $this->build($context);
        $dictionary = $this->dictionary()->with(new Entry(new NameType('First'), new IntegerType($first)));

        return (new StreamObject($bytes, $dictionary, ...$this->filters))->toPdf($context);
    }

    /** @return array{string, int} */
    private function build(SerializationContext $context): array
    {
        foreach ($this->members as $member) {
            $context->register($member->value, $member->identifier());
        }
        $header = [];
        $body = '';
        foreach ($this->members as $member) {
            $header[] = (string) $member->objectNumber->value;
            $header[] = (string) strlen($body);
            $body .= $context->serializeStreamValue($member->value) . "\n";
        }
        $prefix = $header === [] ? '' : implode(' ', $header) . "\n";

        return [$prefix . $body, strlen($prefix)];
    }
}
