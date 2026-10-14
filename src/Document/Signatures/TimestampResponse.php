<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

use PDF\Security\SignatureDerReader;

/** RFC 3161 response status is checked before its token is made available. */
final readonly class TimestampResponse
{
    public TimestampToken $token;
    public int $status;
    /** @var list<string> */
    public array $statusText;

    public function __construct(string $der)
    {
        $fields = (new SignatureDerReader())->element($der)->requireTag(48)->children();
        $status = isset($fields[0]) ? $fields[0]->requireTag(48)->children() : [];
        if (count($fields) !== 2 || !isset($status[0]) || !in_array($status[0]->requireTag(2)->value, ["\0", "\x01"], true)) {
            throw new \UnexpectedValueException('The timestamp authority did not grant the request with a token.');
        }
        if (count($status) > 2 || (isset($status[1]) && $status[1]->tag !== 48)) {
            throw new \InvalidArgumentException('A granted timestamp response contains optional free text and no failure flags.');
        }
        $text = [];
        if (isset($status[1])) {
            foreach ($status[1]->children() as $item) {
                $value = $item->requireTag(12)->value;
                if (preg_match('//u', $value) !== 1) {
                    throw new \InvalidArgumentException('Timestamp response status text is UTF-8.');
                }
                $text[] = $value;
            }
            if ($text === []) {
                throw new \InvalidArgumentException('Present timestamp response free text is nonempty.');
            }
        }
        $this->status = ord($status[0]->value);
        $this->statusText = $text;
        $this->token = new TimestampToken($fields[1]->encoding);
    }
}
