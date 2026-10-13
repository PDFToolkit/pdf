<?php

declare(strict_types=1);

namespace PDF\Security;

use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\BooleanType;

/** One public-key crypt filter and its own key/envelopes, PDF32000_2008.pdf, Tables25/27, pp.76–77. */
final readonly class PublicKeyCryptFilter
{
    public function __construct(
        public NameType $name,
        public PublicKeyKeyMaterial $material,
        public PublicKeyCipherOperation $operation,
        public NameType $authorizationEvent = new NameType('DocOpen')
    ) {
        if ($name->value === '' || $name->value === 'Identity' || !in_array($authorizationEvent->value, ['DocOpen', 'EFOpen'], true)
            || !hash_equals($material->key->bytes(), $operation->key->bytes())) {
            throw new \InvalidArgumentException('Named public-key crypt filters require an explicit matching key and standard authorization event.');
        }
    }

    public function dictionary(): DictionaryType
    {
        $entries = [new Entry(new NameType('CFM'), new NameType($this->operation->cipher->value)),
            new Entry(new NameType('AuthEvent'), $this->authorizationEvent), new Entry(new NameType('Length'), $this->material->length->value),
            new Entry(new NameType('Recipients'), $this->material->recipientObject())];
        if ($this->material->scope === PublicKeyRecipientScope::Document) {
            $entries[] = new Entry(new NameType('EncryptMetadata'), new BooleanType($this->material->metadata->value));
        }
        return new DictionaryType(...$entries);
    }
}
