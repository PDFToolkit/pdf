<?php

declare(strict_types=1);

namespace PDF\Security;

/** Minimal definite-length DER construction for the explicit PDF MAC/CMS structures. */
final readonly class CmsDerEncoding
{
    public function element(int $tag, string $value): string
    {
        $size = strlen($value);
        $length = chr($size);
        if ($size >= 128) {
            $encoded = '';
            while ($size > 0) {
                $encoded = chr($size & 255) . $encoded;
                $size >>= 8;
            }
            $length = chr(128 | strlen($encoded)) . $encoded;
        }
        return chr($tag) . $length . $value;
    }

    public function sequence(string ...$elements): string
    {
        return $this->element(48, implode('', $elements));
    }

    public function set(string ...$elements): string
    {
        sort($elements, SORT_STRING);
        return $this->element(49, implode('', $elements));
    }

    public function oid(string $oid): string
    {
        if (!preg_match('/\A[012](?:\.(?:0|[1-9][0-9]*))+\z/', $oid)) {
            throw new \InvalidArgumentException('ASN.1 object identifier arcs are canonical nonnegative decimal integers.');
        }
        foreach (explode('.', $oid) as $arc) {
            if (filter_var($arc, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => PHP_INT_MAX - 80]]) === false) {
                throw new \InvalidArgumentException('ASN.1 object identifier arc exceeds the native integer range.');
            }
        }
        $parts = array_map(intval(...), explode('.', $oid));
        if (count($parts) < 2 || $parts[0] < 0 || $parts[0] > 2 || $parts[1] < 0 || ($parts[0] < 2 && $parts[1] > 39)) {
            throw new \InvalidArgumentException('Invalid ASN.1 object identifier.');
        }
        $arcs = [40 * $parts[0] + $parts[1], ...array_slice($parts, 2)];
        $bytes = '';
        foreach ($arcs as $arc) {
            $encoded = chr($arc & 127);
            while (($arc >>= 7) > 0) {
                $encoded = chr(128 | ($arc & 127)) . $encoded;
            }
            $bytes .= $encoded;
        }
        return $this->element(6, $bytes);
    }

    public function algorithm(string $oid): string
    {
        return $this->sequence($this->oid($oid));
    }

    public function octets(string $value): string
    {
        return $this->element(4, $value);
    }
}
