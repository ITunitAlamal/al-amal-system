<?php

namespace App\Services\Orphans\Import;

/**
 * Search/compare forms of Arabic text. The original text is always kept; these forms are only for matching
 * variants such as «الأم/الام» or «الوسطى/الوسطي». They never rename a person.
 */
final class ArabicText
{
    public static function normalize(?string $text): string
    {
        $text = trim((string) $text);
        if ($text === '') {
            return '';
        }
        $text = (string) preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}\x{200C}-\x{200F}\x{061C}]/u', '', $text);
        $text = strtr($text, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', 'ؤ' => 'و', 'ئ' => 'ي',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);

        return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($text)));
    }

    /**
     * An identity number as typed: Arabic-Indic and Persian digits become Western digits and outer spaces are trimmed.
     * Nothing else changes — no padding, no stripping of leading zeros, no numeric conversion.
     */
    public static function digits(?string $text): string
    {
        return trim(strtr((string) $text, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']));
    }

    /** Category key: normalized, without punctuation and the definite article's spacing differences. */
    public static function key(?string $text): string
    {
        return trim((string) preg_replace('/[\s\p{P}]+/u', ' ', self::normalize($text)));
    }
}
