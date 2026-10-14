<?php

declare(strict_types=1);

namespace PDF\Security;

/** Limited DER inspection for the PDF signature envelopes delegated to RFC3852 by ISO12.8.3. */
final readonly class SignatureDerElement
{
    public function __construct(public int $tag, public string $value, public string $encoding)
    {
    }

    /** @return list<self> */
    public function children(): array
    {
        if (($this->tag & 32) === 0) {
            throw new \InvalidArgumentException('Only constructed DER elements have children.');
        }
        return (new SignatureDerReader())->elements($this->value);
    }

    public function requireTag(int $tag): self
    {
        if ($this->tag !== $tag) {
            throw new \InvalidArgumentException('The signature DER structure has an unexpected tag.');
        }
        return $this;
    }

    public function requireObjectIdentifier(): self
    {
        $this->requireTag(6);
        if ($this->value === '' || (ord($this->value[strlen($this->value) - 1]) & 128) !== 0) {
            throw new \InvalidArgumentException('A DER object identifier contains complete base-128 subidentifiers.');
        }
        for ($index = 0; $index < strlen($this->value); ++$index) {
            if (($index === 0 || (ord($this->value[$index - 1]) & 128) === 0) && $this->value[$index] === "\x80") {
                throw new \InvalidArgumentException('DER object identifier subidentifiers are minimally encoded.');
            }
        }
        return $this;
    }
}
