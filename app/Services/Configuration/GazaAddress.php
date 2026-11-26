<?php

namespace App\Services\Configuration;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class GazaAddress
{
    public function __construct(private readonly GazaGeography $geography) {}

    public static function rules(string $prefix = ''): array
    {
        $rules = [$prefix.'address_other' => ['sometimes', 'boolean'], $prefix.'address_details' => ['nullable', 'string', 'max:10000']];
        foreach (GazaGeography::FIELDS as $field) {
            $rules[$prefix.$field] = ['nullable', 'integer', 'min:1'];
        }

        return $rules;
    }

    /** Resolve labels on the server and retain historical text snapshots. */
    public function normalize(array $input, ?array $stored = null, string $prefix = '', bool $required = true): array
    {
        Validator::make($input, self::rules())->validate();
        $fields = GazaGeography::FIELDS;
        $labels = ['governorate', 'city', 'area', 'neighborhood'];
        $ids = array_map(fn (string $field): ?int => empty($input[$field]) ? null : (int) $input[$field], $fields);
        $previous = array_map(fn (string $field): ?int => empty($stored[$field]) ? null : (int) $stored[$field], $fields);
        $unchanged = $stored !== null && $previous === $ids;
        $other = (bool) ($input['address_other'] ?? false);
        if ($unchanged && ! array_filter($ids) && ! $other) {
            foreach ($labels as $label) {
                if (($input[$label] ?? null) !== ($stored[$label] ?? null)) {
                    throw ValidationException::withMessages([$prefix.'governorate_value_id' => 'لتغيير العنوان السابق اختره من القوائم الجغرافية.']);
                }
            }

            return [...$input, ...array_fill_keys($fields, null), 'address_other' => false];
        }
        $details = trim((string) ($input['address_details'] ?? ''));
        if ($other && $details === '') {
            throw ValidationException::withMessages([$prefix.'address_details' => 'وصف العنوان مطلوب عند اختيار غير مدرج / أخرى.']);
        }
        if (! array_filter($ids) && ! $other && ! $required && $details === '') {
            return [...$input, ...array_fill_keys($fields, null), 'address_other' => false];
        }
        $parent = null;
        $gap = false;
        foreach ($ids as $level => $id) {
            if ($id === null) {
                if (! $unchanged && ! $gap && ! $other && ($level < 2 || ($parent !== null && $this->geography->children($parent) !== []))) {
                    throw ValidationException::withMessages([$prefix.$fields[$level] => 'اختر المستوى التالي أو غير مدرج / أخرى.']);
                }
                $gap = true;
                $input[$labels[$level]] = $level < 2 && $other ? 'غير مدرج' : null;

                continue;
            }
            $node = $this->geography->nodes()->get($id);
            if ($gap || ! $node || $node->type_code !== GazaGeography::TYPES[$level]
                || ($node->parent_id === null ? $parent !== null : (int) $node->parent_id !== $parent)
                || (! $this->geography->active($node) && ! $unchanged)) {
                throw ValidationException::withMessages([$prefix.$fields[$level] => 'العنوان غير فعال أو لا يتبع الاختيار السابق. أعد اختيار التسلسل.']);
            }
            $input[$labels[$level]] = $unchanged ? ($stored[$labels[$level]] ?? $node->name_ar) : $node->name_ar;
            $parent = $id;
        }

        return [...$input, ...array_combine($fields, $ids), 'address_other' => $other, 'address_details' => $details ?: null];
    }

    public function employeeRows(array $rows, array $stored = []): array
    {
        foreach ($rows as $index => $row) {
            $old = empty($row['id']) ? null : ($stored[$row['id']] ?? null);
            if (! empty($row['id']) && $old === null) {
                throw ValidationException::withMessages(["addresses.{$index}.id" => 'العنوان لا يتبع الموظف.']);
            }
            $rows[$index] = $this->normalize($row, $old, "addresses.{$index}.");
        }

        return $rows;
    }

    public function rentalFields(array $data, string $textField): array
    {
        $value = $this->normalize($data, required: filled($data[$textField] ?? null));
        $text = implode(' — ', array_filter([$value['governorate'] ?? null, $value['city'] ?? null, $value['area'] ?? null, $value['neighborhood'] ?? null, $value['address_details'] ?? null]));

        return [...$data, ...array_intersect_key($value, array_flip([...GazaGeography::FIELDS, 'address_other', 'address_details'])), $textField => $textField === 'location' ? mb_substr($text, 0, 255) : $text];
    }
}
