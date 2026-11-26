<?php

namespace App\Services\Validation;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;

/**
 * Turns a database rule that a save request broke (a duplicate code, a value out of range, a date range
 * the table refuses...) into the same Arabic message a form shows for its own validation, instead of a
 * server error page. Forms should still check these rules themselves; this is the safety net, and the
 * exception is still written to the log so a missing form rule can be found and added.
 */
final class DatabaseConflictResponse
{
    private const MESSAGES = [
        '23514' => 'القيم المُدخلة لا تتوافق مع قواعد الحفظ (مثل تاريخ نهاية يسبق البداية أو قيمة سالبة). راجعها ثم أعد الحفظ.',
        '23503' => 'تعذر الحفظ: القيمة المختارة غير موجودة، أو أن السجل مرتبط بسجلات أخرى لا تسمح بهذا التغيير.',
        '23P01' => 'يوجد سجل آخر يتداخل مع هذه الفترة أو القيم. راجع التواريخ ثم أعد الحفظ.',
        '22001' => 'إحدى القيم أطول من الحد المسموح. اختصرها ثم أعد الحفظ.',
        '22003' => 'إحدى القيم الرقمية خارج الحدود المسموحة.',
        '22007' => 'إحدى قيم التاريخ أو الوقت غير صحيحة.',
        '22008' => 'إحدى قيم التاريخ أو الوقت غير صحيحة.',
        '22P02' => 'إحدى القيم بصيغة غير صحيحة (مثل نص في حقل رقمي).',
    ];

    public static function from(QueryException $exception, Request $request): RedirectResponse|JsonResponse|null
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return null;
        }

        $state = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        [$field, $message] = match (true) {
            $state === '23505' => self::duplicate($exception->getMessage()),
            $state === '23502' => self::missing($exception->getMessage()),
            isset(self::MESSAGES[$state]) => ['form', self::MESSAGES[$state]],
            default => [null, null],
        };
        if ($message === null) {
            return null;
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'errors' => [$field => [$message]]], 422);
        }

        return back()->withErrors([$field => $message]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function duplicate(string $error): array
    {
        $columns = preg_match('/Key \(([^)]+)\)=/', $error, $match) ? array_map('trim', explode(',', $match[1])) : [];
        if (! $columns) {
            return ['form', 'يوجد سجل محفوظ بالبيانات نفسها. غيّر القيمة المكررة ثم أعد الحفظ.'];
        }
        $labels = array_map(fn (string $column): string => self::label($column), $columns);
        // Point at the field a person typed (a code or number) rather than a hidden reference column.
        $field = collect($columns)->reject(fn (string $column): bool => str_ends_with($column, '_id'))->last() ?? end($columns);

        return [$field, 'يوجد سجل محفوظ بالقيمة نفسها ('.implode('، ', $labels).'). غيّر القيمة المكررة ثم أعد الحفظ.'];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function missing(string $error): array
    {
        $column = preg_match('/null value in column "([^"]+)"/', $error, $match) ? $match[1] : null;

        return $column
            ? [$column, 'حقل «'.self::label($column).'» مطلوب.']
            : ['form', 'أحد الحقول المطلوبة فارغ.'];
    }

    private static function label(string $column): string
    {
        return Lang::has("validation.attributes.{$column}") ? (string) __("validation.attributes.{$column}") : $column;
    }
}
