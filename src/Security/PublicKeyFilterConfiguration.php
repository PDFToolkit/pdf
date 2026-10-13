<?php

declare(strict_types=1);

namespace PDF\Security;

use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;

/** Explicit string/default-stream/embedded-file selection; each named filter has an independent key. */
final readonly class PublicKeyFilterConfiguration
{
    /** @var array<string, PublicKeyCryptFilter> */
    private array $filters;

    public function __construct(
        public NameType $strings,
        public NameType $streams,
        public NameType $embeddedFiles,
        PublicKeyCryptFilter ...$filters
    ) {
        $named = [];
        foreach ($filters as $filter) {
            if (isset($named[$filter->name->value])) {
                throw new \InvalidArgumentException('A crypt filter name has exactly one corresponding key.');
            }
            $named[$filter->name->value] = $filter;
        }
        if ($named === []) {
            throw new \InvalidArgumentException('A public-key crypt-filter configuration needs at least one filter.');
        }
        foreach ([$strings, $streams, $embeddedFiles] as $selection) {
            if ($selection->value !== 'Identity' && !isset($named[$selection->value])) {
                throw new \InvalidArgumentException('Crypt selectors must refer to Identity or a declared crypt filter.');
            }
        }
        // ISO7.6.3.1, pp.67–68: names prescribed for the two standard modes.
        // Mixed configurations and explicitly selected per-stream filters retain variable names.
        if ($strings->value === $streams->value && $strings->value !== 'Identity' && $strings->value !== 'DefaultCryptFilter') {
            throw new \InvalidArgumentException('Public-key document encryption uses the name DefaultCryptFilter.');
        }
        if ($strings->value === 'Identity' && $streams->value === 'Identity'
            && !in_array($embeddedFiles->value, ['Identity', 'DefEmbeddedFile'], true)) {
            throw new \InvalidArgumentException('Public-key attachment-only encryption uses the name DefEmbeddedFile.');
        }
        foreach ($named as $filter) {
            $default = in_array($filter->name->value, [$strings->value, $streams->value], true);
            if ($default !== ($filter->material->scope === PublicKeyRecipientScope::Document)) {
                throw new \InvalidArgumentException('Default filters have permission-bearing recipient arrays; individual-stream filters have one seed-only string.');
            }
            if (!$default && !$filter->material->metadata->value) {
                throw new \InvalidArgumentException('Individual-stream filters cannot select document metadata encryption.');
            }
            if (!$filter->material->metadata->value && $filter->name->value !== $streams->value) {
                throw new \InvalidArgumentException('EncryptMetadata=false and its key suffix belong only to the default StmF filter.');
            }
        }
        $this->filters = $named;
    }

    /** @return list<PublicKeyCryptFilter> */
    public function filters(): array
    {
        return array_values($this->filters);
    }

    public function selected(NameType $name): ?PublicKeyCryptFilter
    {
        return $name->value === 'Identity' ? null : ($this->filters[$name->value] ?? throw new \OutOfBoundsException('Unknown crypt filter: ' . $name->value));
    }

    public function forStream(StreamObject $stream): ?PublicKeyCryptFilter
    {
        $explicit = (new StreamCryptFilter())->explicit($stream);
        $type = $stream->dictionary()->get('Type');
        return $this->selected($explicit ?? ($type instanceof NameType && $type->value === 'EmbeddedFile' ? $this->embeddedFiles : $this->streams));
    }
}
