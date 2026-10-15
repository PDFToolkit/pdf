<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

use PDF\Syntax\FileStructure\RevisionStreamSnapshot;

/**
 * ISO 32000-1 12.7.3.3, physical pages 443-444; ISO 32000-2 12.7.4.3, pages 548-549.
 * A bounded non-rich-text content proof, independent of live stream objects.
 * The caller separately validates dictionaries, resource identities and field values.
 * Multiple/nested Tx sections, inline images, unknown operators and predictors are unsupported.
 * Tx instructions must own their q/Q isolation and cannot share paths or text objects
 * with surrounding content: marked-content boundaries do not restore graphics state.
 * This proves region/state boundaries, not complete operand conformity or visual intent.
 */
final readonly class VariableTextAppearanceChanges
{
    private const string WHITE = "\x00\x09\x0a\x0c\x0d\x20";
    private const string DELIMITERS = '()<>[]{}/%';
    private const array OPERATORS = [
        'b', 'B', 'b*', 'B*', 'BDC', 'BMC', 'BT', 'BX', 'c', 'cm', 'CS', 'cs',
        'd', 'Do', 'DP', 'EMC', 'ET', 'EX', 'f', 'F', 'f*', 'G', 'g',
        'gs', 'h', 'i', 'j', 'J', 'K', 'k', 'l', 'm', 'M', 'MP', 'n', 'q', 'Q',
        're', 'RG', 'rg', 'ri', 's', 'S', 'SC', 'sc', 'SCN', 'scn', 'sh', 'T*',
        'Tc', 'Td', 'TD', 'Tf', 'Tj', 'TJ', 'TL', 'Tm', 'Tr', 'Ts', 'Tw', 'Tz',
        'v', 'w', 'W', 'W*', 'y', "'", '"',
    ];

    public function __construct(private int $maximumBytes = 1048576, private int $maximumTokens = 100000, private int $maximumNesting = 64)
    {
        if ($maximumBytes < 1 || $maximumTokens < 1 || $maximumNesting < 1) {
            throw new \InvalidArgumentException('Appearance content limits must be positive.');
        }
    }

    public function validate(RevisionStreamSnapshot $old, RevisionStreamSnapshot $new): void
    {
        $before = (new ProtectedStreamDecoder($this->maximumBytes))->decode($old);
        $after = (new ProtectedStreamDecoder($this->maximumBytes))->decode($new);
        $oldSection = $this->section($before);
        $newSection = $this->section($after);
        if ($before === $after) {
            return;
        }
        if ($newSection === null) {
            throw new \LogicException('Changed variable text requires an explicit Tx BMC section.');
        }
        if ($oldSection !== null) {
            if (substr($before, 0, $oldSection[0]) !== substr($after, 0, $newSection[0])
                || substr($before, $oldSection[1]) !== substr($after, $newSection[1])) {
                throw new \LogicException('Variable text preserves every byte outside its Tx section.');
            }
            return;
        }
        if (!str_starts_with($after, $before) || $newSection[0] < strlen($before)
            || !$this->trivia(substr($after, strlen($before), $newSection[0] - strlen($before)))
            || !$this->trivia(substr($after, $newSection[1]))) {
            throw new \LogicException('An appearance without Tx permits only an appended Tx section.');
        }
    }

    /** @return array{int, int}|null Byte span includes the tag, BMC and matching EMC. */
    private function section(string $bytes): ?array
    {
        $offset = 0;
        $tokens = 0;
        $operands = [];
        $marked = [];
        $graphics = [];
        $text = null;
        $active = null;
        $section = null;
        $compatibility = 0;
        $path = false;
        while (($token = $this->token($bytes, $offset, $tokens, 0)) !== null) {
            if ($token['kind'] !== 'operator') {
                if (count($operands) >= 64) {
                    throw new \LogicException('Appearance operator operands exceed their bound.');
                }
                $operands[] = $token;
                continue;
            }
            $operator = $token['value'];
            if ($active !== null && count($graphics) <= $active[1]
                && !in_array($operator, ['BMC', 'BDC', 'EMC', 'q', 'Q', 'BX', 'EX', 'MP', 'DP'], true)) {
                throw new \LogicException('Variable text instructions require a graphics scope owned by the Tx section.');
            }
            if (in_array($operator, ['BMC', 'BDC'], true)) {
                if (count($operands) !== ($operator === 'BMC' ? 1 : 2) || $operands[0]['kind'] !== 'name'
                    || ($operator === 'BDC' && !in_array($operands[1]['kind'], ['name', 'dictionary'], true))) {
                    throw new \LogicException('Malformed marked-content operands in an appearance.');
                }
                $isText = $operands[0]['value'] === 'Tx';
                if (count($marked) >= $this->maximumNesting || ($isText && ($operator !== 'BMC' || $active !== null || $section !== null))) {
                    throw new \LogicException('Appearance content requires at most one non-nested Tx BMC section.');
                }
                if ($isText) {
                    if ($text !== null || $path) {
                        throw new \LogicException('A Tx section cannot inherit an open text object or current path.');
                    }
                    $active = [$operands[0]['start'], count($graphics), $text, $compatibility];
                }
                $marked[] = $isText;
            } elseif ($operator === 'EMC') {
                if ($operands !== [] || $marked === []) {
                    throw new \LogicException('Unmatched appearance marked-content boundary.');
                }
                if (array_pop($marked)) {
                    if ($active === null || count($graphics) !== $active[1] || $text !== $active[2] || $compatibility !== $active[3] || $path) {
                        throw new \LogicException('A Tx section cannot leak graphics, text or compatibility scopes.');
                    }
                    $section = [$active[0], $token['end']];
                    $active = null;
                }
            } elseif (in_array($operator, ['q', 'Q', 'BT', 'ET', 'BX', 'EX'], true)) {
                if ($operands !== []) {
                    throw new \LogicException('Appearance scope operators cannot have operands.');
                }
                if ($operator === 'q') {
                    if (count($graphics) >= $this->maximumNesting) {
                        throw new \LogicException('Appearance graphics nesting exceeds its bound.');
                    }
                    $graphics[] = $token['start'];
                } elseif ($operator === 'Q') {
                    $start = array_pop($graphics);
                    if ($start === null || ($active !== null && $start < $active[0])) {
                        throw new \LogicException('Appearance graphics restoration crosses its allowed scope.');
                    }
                    if ($active !== null && ($path || $text !== null)) {
                        throw new \LogicException('A Tx graphics scope cannot restore with a pending path, clipping operation or text object.');
                    }
                } elseif ($operator === 'BT') {
                    if ($text !== null) {
                        throw new \LogicException('Appearance text objects cannot nest.');
                    }
                    $text = $token['start'];
                } elseif ($operator === 'ET') {
                    if ($text === null || ($active !== null && $text < $active[0])) {
                        throw new \LogicException('Appearance text termination crosses its allowed scope.');
                    }
                    $text = null;
                } elseif ($operator === 'BX') {
                    if (++$compatibility > $this->maximumNesting) {
                        throw new \LogicException('Appearance compatibility nesting exceeds its bound.');
                    }
                } elseif (--$compatibility < 0 || ($active !== null && $compatibility < $active[3])) {
                    throw new \LogicException('Appearance compatibility termination crosses its allowed scope.');
                }
            }
            // The current path is not part of q/Q's saved graphics state. W/W* also
            // defer clipping until a path-ending operator, including an empty n.
            if (in_array($operator, ['m', 'l', 'c', 'v', 'y', 'h', 're', 'W', 'W*'], true)) {
                $path = true;
            } elseif (in_array($operator, ['S', 's', 'f', 'F', 'f*', 'B', 'B*', 'b', 'b*', 'n'], true)) {
                $path = false;
            }
            $operands = [];
        }
        if ($operands !== [] || $marked !== [] || $graphics !== [] || $text !== null || $compatibility !== 0) {
            throw new \LogicException('Appearance content has unfinished operands or scopes.');
        }
        return $section;
    }

    /** @return array{kind:string, value:string, start:int, end:int}|null */
    private function token(string $bytes, int &$offset, int &$tokens, int $depth): ?array
    {
        $this->skipTrivia($bytes, $offset);
        $length = strlen($bytes);
        if ($offset === $length) {
            return null;
        }
        if (++$tokens > $this->maximumTokens || $depth > $this->maximumNesting) {
            throw new \LogicException('Appearance lexical limits exceeded.');
        }
        $start = $offset;
        $first = $bytes[$offset++];
        $kind = 'object';
        $value = '';
        if ($first === '(') {
            if ($depth >= $this->maximumNesting) {
                throw new \LogicException('Appearance string nesting exceeds its bound.');
            }
            $nesting = 1;
            while ($offset < $length && $nesting > 0) {
                $character = $bytes[$offset++];
                if ($character === '\\') {
                    if ($offset === $length) {
                        break;
                    }
                    if ($bytes[$offset++] === "\r" && $offset < $length && $bytes[$offset] === "\n") {
                        ++$offset;
                    }
                } elseif ($character === '(') {
                    if (++$nesting + $depth > $this->maximumNesting) {
                        throw new \LogicException('Appearance string nesting exceeds its bound.');
                    }
                } elseif ($character === ')') {
                    --$nesting;
                }
            }
            if ($nesting !== 0) {
                throw new \LogicException('Unterminated appearance literal string.');
            }
        } elseif ($first === '[' || ($first === '<' && $offset < $length && $bytes[$offset] === '<')) {
            if ($depth >= $this->maximumNesting) {
                throw new \LogicException('Appearance container nesting exceeds its bound.');
            }
            $dictionary = $first === '<';
            $kind = $dictionary ? 'dictionary' : 'array';
            if ($dictionary) {
                ++$offset;
            }
            $keys = [];
            $expectKey = true;
            while (true) {
                $this->skipTrivia($bytes, $offset);
                if ((!$dictionary && substr($bytes, $offset, 1) === ']') || ($dictionary && substr($bytes, $offset, 2) === '>>')) {
                    if ($dictionary && !$expectKey) {
                        throw new \LogicException('An appearance dictionary key has no value.');
                    }
                    $offset += $dictionary ? 2 : 1;
                    break;
                }
                $item = $this->token($bytes, $offset, $tokens, $depth + 1);
                if ($item === null || $item['kind'] === 'operator') {
                    throw new \LogicException('Malformed appearance array or dictionary.');
                }
                if ($dictionary) {
                    if ($expectKey && ($item['kind'] !== 'name' || isset($keys[$item['value']]))) {
                        throw new \LogicException('Appearance dictionary keys must be unique names.');
                    }
                    if ($expectKey) {
                        $keys[$item['value']] = true;
                    }
                    $expectKey = !$expectKey;
                }
            }
        } elseif ($first === '<') {
            while ($offset < $length && $bytes[$offset] !== '>') {
                if (!str_contains(self::WHITE, $bytes[$offset]) && !ctype_xdigit($bytes[$offset])) {
                    throw new \LogicException('Invalid appearance hexadecimal string.');
                }
                ++$offset;
            }
            if ($offset === $length) {
                throw new \LogicException('Unterminated appearance hexadecimal string.');
            }
            ++$offset;
        } elseif ($first === '/') {
            $kind = 'name';
            while ($offset < $length && !$this->separator($bytes[$offset])) {
                $character = $bytes[$offset++];
                if ($character === '#') {
                    $escape = substr($bytes, $offset, 2);
                    if (strlen($escape) !== 2 || !ctype_xdigit($escape) || $escape === '00') {
                        throw new \LogicException('Invalid appearance name escape.');
                    }
                    $character = chr((int) hexdec($escape));
                    $offset += 2;
                }
                $value .= $character;
            }
        } else {
            if (str_contains(self::DELIMITERS, $first)) {
                throw new \LogicException('Unexpected appearance delimiter.');
            }
            while ($offset < $length && !$this->separator($bytes[$offset])) {
                ++$offset;
            }
            $value = substr($bytes, $start, $offset - $start);
            $unsigned = ltrim($value, '+-');
            $number = $unsigned !== '' && strlen($value) - strlen($unsigned) <= 1
                && strspn($unsigned, '0123456789.') === strlen($unsigned)
                && substr_count($unsigned, '.') <= 1 && strpbrk($unsigned, '0123456789') !== false;
            if (!$number && !in_array($value, ['true', 'false', 'null'], true)) {
                if (!in_array($value, self::OPERATORS, true)) {
                    throw new \LogicException('Inline images and unknown appearance operators are unsupported.');
                }
                $kind = 'operator';
            }
        }
        return ['kind' => $kind, 'value' => $value, 'start' => $start, 'end' => $offset];
    }

    private function separator(string $character): bool
    {
        return str_contains(self::WHITE, $character) || str_contains(self::DELIMITERS, $character);
    }

    private function skipTrivia(string $bytes, int &$offset): void
    {
        $length = strlen($bytes);
        while ($offset < $length) {
            if (str_contains(self::WHITE, $bytes[$offset])) {
                ++$offset;
            } elseif ($bytes[$offset] === '%') {
                while ($offset < $length && $bytes[$offset] !== "\r" && $bytes[$offset] !== "\n") {
                    ++$offset;
                }
            } else {
                break;
            }
        }
    }

    private function trivia(string $bytes): bool
    {
        $offset = 0;
        $this->skipTrivia($bytes, $offset);
        return $offset === strlen($bytes);
    }
}
