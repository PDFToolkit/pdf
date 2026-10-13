<?php

declare(strict_types=1);

namespace PDF\Graphics\OptionalContent;

use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;

final class LanguageUsage extends UsageEntry
{
    public function __construct(public readonly TextString $language, public readonly BooleanType $preferred = new BooleanType(false))
    {
        if ($language->text === '') {
            throw new \InvalidArgumentException('A language usage declaration must name a language.');
        }
        parent::__construct(UsageCategory::Language, new Entry(new NameType('Lang'), $language), new Entry(new NameType('Preferred'), new NameType($preferred->value ? 'ON' : 'OFF')));
    }

    public function matches(TextString $system, bool $exact): bool
    {
        return $exact ? strcasecmp($this->language->text, $system->text) === 0 : strcasecmp(explode('-', $this->language->text)[0], explode('-', $system->text)[0]) === 0;
    }
}
