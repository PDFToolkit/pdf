<?php

declare(strict_types=1);

namespace PDF\Document\Navigation;

use PDF\Document\DocumentGraphContext;
use PDF\Document\Files\FileSpecificationRules;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringType;

/** Resolves only the effective action graph; performs no I/O and never invokes an action. */
final readonly class ActionDictionaryValues
{
    public function __construct(public DictionaryType $dictionary, public DocumentGraphContext $graph)
    {
    }

    public function entry(string $key, bool $required = false): ?BasicType
    {
        $entry = $this->graph->entry($this->dictionary, $key);
        if ($entry === null && $required) {
            throw new \InvalidArgumentException('An active action requires its ' . $key . ' entry.');
        }
        return $entry;
    }

    public function value(string $key, bool $required = false): ?BasicType
    {
        $entry = $this->entry($key, $required);
        return $entry === null ? null : $this->graph->resolve($entry);
    }

    /** @param non-empty-list<class-string<BasicType>> $types */
    public function typed(string $key, array $types, bool $required = false): ?BasicType
    {
        $value = $this->value($key, $required);
        if ($value === null) {
            return null;
        }
        foreach ($types as $type) {
            if ($value instanceof $type) {
                return $value;
            }
        }
        throw new \InvalidArgumentException('An active action has an invalid ' . $key . ' value type.');
    }

    /** @param list<string>|null $allowed */
    public function name(string $key, ?array $allowed = null, bool $required = false): ?NameType
    {
        $value = $this->typed($key, [NameType::class], $required);
        if ($value === null) {
            return null;
        }
        if (!$value instanceof NameType || ($allowed !== null && !in_array($value->value, $allowed, true))) {
            throw new \InvalidArgumentException('An active action has an invalid ' . $key . ' name.');
        }
        return $value;
    }

    public function child(string $key, bool $required = false, bool $indirect = false): ?self
    {
        $entry = $this->entry($key, $required);
        if ($entry === null) {
            return null;
        }
        $dictionary = $this->graph->dictionary($entry);
        if ($dictionary === null || $this->graph->resolve($entry) instanceof StreamObject || ($indirect && !$entry instanceof IndirectReference)) {
            throw new \InvalidArgumentException('An active action ' . $key . ' requires ' . ($indirect ? 'an indirect' : 'a') . ' dictionary.');
        }
        return new self($dictionary, $this->graph);
    }

    public function file(string $key, bool $required = false, bool $url = false): void
    {
        $value = $this->value($key, $required);
        if ($value === null || $value instanceof StringType) {
            return;
        }
        $file = $this->child($key, true) ?? throw new \LogicException('A required file specification was checked.');
        (new FileSpecificationRules())->validate($file->dictionary, $this->graph);
        if ($url) {
            $file->name('FS', ['URL'], true);
            $file->typed('F', [StringType::class], true);
        }
    }
}
