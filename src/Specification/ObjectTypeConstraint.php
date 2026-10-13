<?php

declare(strict_types=1);

namespace PDF\Specification;

use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringType;

final readonly class ObjectTypeConstraint
{
    /** @var list<class-string<BasicType>> */
    public array $classes;

    public function __construct(
        public string $specificationType,
        public bool $indirect = false,
        public bool $direct = false,
        public bool $directArrayElements = false,
        public ?string $fixedName = null,
        public bool $indirectArrayElements = false,
        public ?self $arrayElementConstraint = null,
    ) {
        if ($directArrayElements && $indirectArrayElements) {
            throw new \InvalidArgumentException('Array elements cannot be both direct and indirect.');
        }
        $this->classes = $this->objectTypes($specificationType);
    }

    public function validate(BasicType $value): void
    {
        if ($this->indirect && !$value instanceof IndirectReference) {
            throw new \InvalidArgumentException('This value shall be an indirect reference.');
        }
        if ($this->direct && $value instanceof IndirectReference) {
            throw new \InvalidArgumentException('This value shall be a direct object.');
        }
        $target = $value instanceof IndirectReference ? $value->target() : $value;
        if ($target instanceof StreamObject && !$value instanceof IndirectReference) {
            throw new \InvalidArgumentException('Stream values require an explicit indirect reference.');
        }
        $accepted = false;
        foreach ($this->classes as $class) {
            if ($target instanceof $class) {
                $accepted = true;
                break;
            }
        }
        if (!$accepted) {
            throw new \InvalidArgumentException('Expected PDF type ' . $this->specificationType . '; received ' . $target::class . '.');
        }
        if ($this->fixedName !== null && (!$target instanceof NameType || $target->value !== $this->fixedName)) {
            throw new \InvalidArgumentException('This dictionary entry shall be /' . $this->fixedName . '.');
        }
        if ($this->specificationType === 'rectangle') {
            if (!$target instanceof ArrayType || count($target) !== 4) {
                throw new \InvalidArgumentException('A rectangle requires four direct numbers.');
            }
            foreach ($target->items as $item) {
                if (!$item instanceof NumericType) {
                    throw new \InvalidArgumentException('A rectangle requires four direct numbers.');
                }
            }
        }
        if ($target instanceof ArrayType) {
            if ($this->arrayElementConstraint !== null) {
                foreach ($target->items as $item) {
                    $this->arrayElementConstraint->validate($item);
                }
            }
            if ($this->indirectArrayElements) {
                foreach ($target->items as $item) {
                    if (!$item instanceof IndirectReference) {
                        throw new \InvalidArgumentException('This array requires indirect references as its elements.');
                    }
                }
            }
            if ($this->directArrayElements) {
                foreach ($target->items as $item) {
                    if ($item instanceof IndirectReference) {
                        throw new \InvalidArgumentException('This array requires direct elements.');
                    }
                }
            }
            $this->validateArrayMembers($target);
        }
    }

    private function validateArrayMembers(ArrayType $array): void
    {
        $parts = preg_split('/\s*,\s*(?:or\s+)?|\s+or\s+/', strtolower($this->specificationType)) ?: [];
        $domains = [];
        foreach ($parts as $part) {
            if (str_starts_with($part, 'array of ')) {
                $domains[] = match (substr($part, 9)) {
                    'dictionaries' => DictionaryType::class,
                    'text strings', 'ascii strings', 'strings', 'text string', 'byte strings' => StringType::class,
                    'names' => NameType::class,
                    'numbers', 'reals' => NumericType::class,
                    'integers' => IntegerType::class,
                    default => BasicType::class,
                };
            }
        }
        if ($domains === []) {
            return;
        }
        foreach ($domains as $memberType) {
            $accepted = true;
            foreach ($array->items as $item) {
                $member = $item instanceof IndirectReference ? $item->target() : $item;
                if (!$member instanceof $memberType) {
                    $accepted = false;
                    break;
                }
            }
            if ($accepted) {
                return;
            }
        }
        throw new \InvalidArgumentException('Invalid array member type.');
    }

    /** @return list<class-string<BasicType>> */
    private function objectTypes(string $specificationType): array
    {
        $type = strtolower(trim($specificationType, " \t\n\r\0\x0B‘’"));
        if (in_array($type, ['(any)', '(various)', '(undefined)', 'any', 'various', 'undefined'], true)) {
            return [BasicType::class];
        }
        $types = [];
        foreach (preg_split('/\s*,\s*(?:or\s+)?|\s+or\s+/', $type) ?: [] as $part) {
            $classes = str_starts_with($part, 'array of ') ? [ArrayType::class] : match ($part) {
                'name' => [NameType::class], 'integer' => [IntegerType::class],
                'number', 'real' => [NumericType::class], 'boolean' => [BooleanType::class],
                'array', 'rectangle' => [ArrayType::class],
                'dictionary', 'name tree', 'number tree' => [DictionaryType::class],
                'stream', 'text stream' => [StreamObject::class],
                'function' => [DictionaryType::class, StreamObject::class],
                'file specification' => [StringType::class, DictionaryType::class],
                'string', 'text string', 'byte string', 'ascii string', 'pdfdocencoded string', 'date', 'date string', 'text' => [StringType::class],
                default => throw new \LogicException('Unmapped specification type: ' . $specificationType),
            };
            array_push($types, ...$classes);
        }
        return array_values(array_unique($types));
    }
}
