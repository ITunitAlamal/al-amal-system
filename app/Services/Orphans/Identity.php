<?php

namespace App\Services\Orphans;

use Illuminate\Support\Facades\DB;

/**
 * Identity numbers are strings: never cast, padded or stripped. A 9-digit Palestinian ID is only a FORMAT check
 * («صيغة صحيحة»); it is not civil-registry verification, which stays manual until a real integration exists.
 */
final class Identity
{
    public const FORMAT_LABELS = [
        'missing' => 'غير متوفر',
        'valid_format' => 'صيغة صحيحة (غير متحقق من السجل المدني)',
        'invalid_format' => 'صيغة غير صحيحة',
        'placeholder' => 'رقم وهمي أو مؤقت',
        'other_type' => 'وثيقة غير فلسطينية',
    ];

    public static function clean(?string $value): string
    {
        return (string) preg_replace('/\s+/u', '', ArabicText::digits($value));
    }

    public static function hash(string $clean): string
    {
        return hash_hmac('sha256', $clean, (string) config('app.key'));
    }

    /** True for obvious stand-ins: one repeated digit, ascending/descending runs, or mostly zeros. */
    public static function isPlaceholder(string $clean): bool
    {
        if ($clean === '' || ! ctype_digit($clean)) {
            return false;
        }

        return count(array_unique(str_split($clean))) === 1
            || str_contains('01234567890123456789', $clean) || str_contains('98765432109876543210', $clean)
            || substr_count($clean, '0') >= strlen($clean) - 1;
    }

    /** @return array{0: string, 1: string} [format, cleaned value] */
    public static function classify(?string $value, ?int $typeValueId): array
    {
        $clean = self::clean($value);
        if ($clean === '') {
            return ['missing', ''];
        }
        if (! self::isPalestinian($typeValueId)) {
            return [self::isPlaceholder($clean) ? 'placeholder' : 'other_type', $clean];
        }
        if (self::isPlaceholder($clean)) {
            return ['placeholder', $clean];
        }

        return [preg_match('/'.str_replace('/', '\/', self::pattern()).'/Du', $clean) ? 'valid_format' : 'invalid_format', $clean];
    }

    /** Syntax of the Palestinian ID, from «ثوابت النظام» (metadata.pattern); 9 digits when unset or invalid. */
    public static function pattern(): string
    {
        static $pattern = null;
        if ($pattern === null) {
            $meta = json_decode((string) DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')
                ->where('t.code', 'identity_document_types')->where('v.code', 'palestinian_id')->value('v.metadata'), true);
            $candidate = is_array($meta) && is_string($meta['pattern'] ?? null) ? $meta['pattern'] : '';
            $pattern = $candidate !== '' && @preg_match('/'.str_replace('/', '\/', $candidate).'/Du', '') !== false ? $candidate : '^\d{9}$';
        }

        return $pattern;
    }

    /** Without a document type the number is treated as a Palestinian ID (the institute's default document). */
    public static function isPalestinian(?int $typeValueId): bool
    {
        if ($typeValueId === null) {
            return true;
        }

        return DB::table('master_data_values')->where('id', $typeValueId)->value('code') === 'palestinian_id';
    }

    /** Arabic hint of the configured syntax (metadata.pattern_hint), e.g. «9 أرقام». */
    public static function hint(): ?string
    {
        static $hint = false;
        if ($hint === false) {
            $meta = json_decode((string) DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')
                ->where('t.code', 'identity_document_types')->where('v.code', 'palestinian_id')->value('v.metadata'), true);
            $hint = is_array($meta) && filled($meta['pattern_hint'] ?? null) ? (string) $meta['pattern_hint'] : null;
        }

        return $hint;
    }

    public static function mask(?string $last4): string
    {
        return $last4 ? '•••••'.$last4 : '—';
    }
}
