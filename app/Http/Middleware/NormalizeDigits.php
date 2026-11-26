<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\TransformsRequest;

/**
 * Converts Arabic-Indic (٠١٢…) and Persian (۰۱۲…) digits typed in form fields to the digits 0–9,
 * so phone numbers, identity numbers, amounts and dates entered from an Arabic keyboard pass validation.
 * Password fields are left exactly as typed.
 */
class NormalizeDigits extends TransformsRequest
{
    private const DIGITS = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    /**
     * @var list<string>
     */
    private const EXCEPT = ['password', 'password_confirmation', 'current_password'];

    protected function transform($key, $value)
    {
        if (! is_string($value) || in_array($key, self::EXCEPT, true)) {
            return $value;
        }

        return strtr($value, self::DIGITS);
    }
}
