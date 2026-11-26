<?php

namespace App\Services\Orphans;

use App\Services\Orphans\Import\ArabicText;

/**
 * Search tokens for names that are stored encrypted: keyed hashes (HMAC with the application key) of the normalized
 * name parts and their prefixes. The index never holds readable text. It answers:
 * - «الاسم الرباعي» per part, exact («p{n}=») or starts-with («p{n}^»); the family name (part 4) keeps every word
 *   («p4w^») so a multi-word family name matches on any of its words;
 * - the general search, each typed word as the start of any word of the name («w^»).
 * Positions come only from the parts the user entered; a free-text name gives word tokens only, nothing is guessed.
 */
final class NameIndex
{
    public const PARTS = [1 => 'first_name', 2 => 'father_name', 3 => 'grandfather_name', 4 => 'family_name'];

    private const MAX_PREFIX = 12;

    /**
     * @param  array<string, ?string>|null  $parts
     * @return list<string>
     */
    public static function tokens(?array $parts, ?string $fullName = null): array
    {
        $tokens = [];
        foreach (self::PARTS as $n => $key) {
            $value = ArabicText::normalize($parts[$key] ?? '');
            if ($value === '') {
                continue;
            }
            $tokens[] = self::exact($n, $value);
            foreach (self::prefixes($value) as $prefix) {
                $tokens[] = self::hash("p{$n}^".$prefix);
            }
            if ($n === 4) {
                foreach (explode(' ', $value) as $word) {
                    foreach (self::prefixes($word) as $prefix) {
                        $tokens[] = self::hash('p4w^'.$prefix);
                    }
                }
            }
        }
        $full = ArabicText::normalize($fullName ?? implode(' ', array_filter(array_map(fn (string $k): string => (string) ($parts[$k] ?? ''), self::PARTS))));
        foreach (array_unique(array_filter(explode(' ', $full))) as $word) {
            foreach (self::prefixes($word) as $prefix) {
                $tokens[] = self::hash('w^'.$prefix);
            }
        }

        return array_values(array_unique($tokens));
    }

    /** Token for one «الاسم الرباعي» part as typed; null when the part is empty. */
    public static function part(int $n, string $term, bool $exact): ?string
    {
        $term = ArabicText::normalize($term);
        if ($term === '') {
            return null;
        }
        if ($exact) {
            return self::exact($n, $term);
        }

        return self::hash(($n === 4 && ! str_contains($term, ' ') ? 'p4w^' : "p{$n}^").mb_substr($term, 0, self::MAX_PREFIX));
    }

    /** @return list<string> one token per typed word (start of any word of the name) */
    public static function words(string $text): array
    {
        return array_values(array_map(fn (string $w): string => self::hash('w^'.mb_substr($w, 0, self::MAX_PREFIX)),
            array_slice(array_filter(explode(' ', ArabicText::normalize($text))), 0, 8)));
    }

    /** PostgreSQL text[] literal (tokens are hex, so no quoting is needed). @param list<string> $tokens */
    public static function literal(array $tokens): string
    {
        return '{'.implode(',', $tokens).'}';
    }

    private static function exact(int $n, string $value): string
    {
        return self::hash("p{$n}=".$value);
    }

    /** @return list<string> */
    private static function prefixes(string $value): array
    {
        $chars = mb_str_split($value);
        $out = [];
        for ($i = 1; $i <= min(count($chars), self::MAX_PREFIX); $i++) {
            $out[] = implode('', array_slice($chars, 0, $i));
        }

        return $out;
    }

    private static function hash(string $value): string
    {
        return substr(hash_hmac('sha256', 'orphan-name|'.$value, (string) config('app.key')), 0, 24);
    }
}
