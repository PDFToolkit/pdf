<?php

declare(strict_types=1);

namespace PDF\Security;

use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;

/** Explicit StrF, StmF and EFF selectors for the standard handler, ISO pp64–78. */
final readonly class StandardCryptFilters
{
    public function __construct(
        public NameType $strings = new NameType('StdCF'),
        public NameType $streams = new NameType('StdCF'),
        public NameType $embeddedFiles = new NameType('StdCF'),
        private StreamCryptFilter $selector = new StreamCryptFilter(),
    ) {
        foreach ([$strings, $streams, $embeddedFiles] as $name) {
            $this->validate($name);
        }
    }

    public function forStream(StreamObject $stream): NameType
    {
        $selected = $this->selector->explicit($stream);
        if ($selected === null) {
            $type = $stream->dictionary()->get('Type');
            $selected = $type instanceof NameType && $type->value === 'EmbeddedFile' ? $this->embeddedFiles : $this->streams;
        }
        $this->validate($selected);
        return $selected;
    }

    private function validate(NameType $name): void
    {
        if (!in_array($name->value, ['StdCF', 'Identity'], true)) {
            throw new \InvalidArgumentException('Standard crypt filters are StdCF or Identity.');
        }
    }
}
