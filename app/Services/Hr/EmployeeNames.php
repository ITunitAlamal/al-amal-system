<?php

namespace App\Services\Hr;

/**
 * Name rules shared by profile creation, the staff import and account linking.
 */
final class EmployeeNames
{
    /**
     * The account name starts with the profile's first name and ends with its family name. Spelling
     * variants that are common between two typists (أ/إ/آ/ا, ى/ي, ة/ه, tatweel, extra spaces) are ignored.
     */
    public static function accountMatches(string $accountName, string $firstName, string $familyName): bool
    {
        $account = self::normalize($accountName);
        $first = self::normalize($firstName);
        $family = self::normalize($familyName);

        return $first !== '' && $family !== ''
            && str_starts_with($account.' ', $first.' ')
            && str_ends_with(' '.$account, ' '.$family);
    }

    public static function normalize(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', strtr(str_replace('ـ', '', $text), [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه',
        ])));
    }

    /** The name shown for a profile: the full source name when it exists, otherwise the recorded parts. */
    public static function display(object $employee): string
    {
        $full = trim((string) ($employee->full_name_ar ?? ''));

        return $full !== '' ? $full : trim(implode(' ', array_filter([
            $employee->first_name_ar ?? null, $employee->father_name_ar ?? null, $employee->grandfather_name_ar ?? null, $employee->family_name_ar ?? null,
        ])));
    }
}
