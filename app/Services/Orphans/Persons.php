<?php

namespace App\Services\Orphans;

use App\Models\Person;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Canonical person records: validation, identity classification, search forms and duplicate detection.
 * Names are saved exactly as entered; the normalized columns exist only for search.
 */
final class Persons
{
    public const NAME_PARTS = ['first_name' => 'n_first', 'father_name' => 'n_father', 'grandfather_name' => 'n_grandfather', 'family_name' => 'n_family'];

    public const VITAL_LABELS = ['alive' => 'على قيد الحياة', 'deceased' => 'متوفى', 'missing' => 'مفقود', 'unknown' => 'غير محدد'];

    public const SEX_LABELS = ['male' => 'ذكر', 'female' => 'أنثى'];

    /** @return array<string, list<mixed>> */
    public static function rules(string $prefix = '', bool $required = true): array
    {
        $p = fn (string $field): string => $prefix.$field;
        $master = Rule::exists('master_data_values', 'id');

        return [
            $p('first_name') => ['nullable', 'string', 'max:100'],
            $p('father_name') => ['nullable', 'string', 'max:100'],
            $p('grandfather_name') => ['nullable', 'string', 'max:100'],
            $p('family_name') => ['nullable', 'string', 'max:100'],
            $p('extra_names') => ['nullable', 'string', 'max:200'],
            $p('full_name') => [$required ? 'required_without_all:'.$p('first_name').','.$p('family_name') : 'nullable', 'nullable', 'string', 'max:400'],
            $p('name_en') => ['nullable', 'string', 'max:300'],
            $p('id_type_value_id') => ['nullable', 'integer', $master],
            $p('id_number') => ['nullable', 'string', 'max:40'],
            $p('sex') => ['nullable', Rule::in(['male', 'female'])],
            $p('date_of_birth') => ['nullable', 'date', 'before_or_equal:today'],
            $p('dob_status') => ['nullable', Rule::in(['declared', 'verified', 'estimated'])],
            $p('nationality_value_id') => ['nullable', 'integer', $master],
            $p('place_of_birth') => ['nullable', 'string', 'max:150'],
            $p('vital_status') => ['nullable', Rule::in(array_keys(self::VITAL_LABELS))],
            $p('phone') => ['nullable', 'string', 'regex:/^\+?[0-9٠-٩]{7,15}$/u'],
            $p('alt_phone') => ['nullable', 'string', 'regex:/^\+?[0-9٠-٩]{7,15}$/u'],
            $p('email') => ['nullable', 'email', 'max:150'],
            $p('notes') => ['nullable', 'string', 'max:3000'],
        ];
    }

    /** Field labels for validation messages. @return array<string, string> */
    public static function attributes(string $prefix = '', string $who = ''): array
    {
        $suffix = $who !== '' ? ' ('.$who.')' : '';
        $labels = ['first_name' => 'الاسم الأول', 'father_name' => 'اسم الأب', 'grandfather_name' => 'اسم الجد', 'family_name' => 'اسم العائلة',
            'full_name' => 'الاسم الكامل', 'id_number' => 'رقم الهوية', 'date_of_birth' => 'تاريخ الميلاد', 'phone' => 'رقم الجوال', 'alt_phone' => 'رقم الجوال الإضافي', 'email' => 'البريد الإلكتروني'];

        return collect($labels)->mapWithKeys(fn (string $label, string $field): array => [$prefix.$field => $label.$suffix])->all();
    }

    /**
     * Creates or updates a person from validated input (unprefixed keys). An identity already registered as a valid
     * document belongs to an existing person: the caller must reuse that person (compare screen), never duplicate it.
     */
    public function save(array $data, ?Person $person, ?int $actor, string $errorKey = 'id_number'): Person
    {
        $person = $this->assign($data, $person ?? new Person, $errorKey);
        $person->created_by ??= $actor;
        $person->updated_by = $actor;
        $person->save();

        return $person;
    }

    /**
     * What a save of $data would make of $person, as an unsaved copy (the same normalization and the same
     * duplicate-identity refusal as save()); $person itself is untouched. Used to route sensitive registry changes
     * through a request: the copy's dirty attributes are the change.
     */
    public function preview(array $data, Person $person, string $errorKey = 'id_number'): Person
    {
        return $this->assign($data, clone $person, $errorKey);
    }

    private function assign(array $data, Person $person, string $errorKey): Person
    {
        $fill = [];
        foreach (['first_name', 'father_name', 'grandfather_name', 'family_name', 'extra_names', 'name_en', 'sex', 'date_of_birth', 'dob_status',
            'nationality_value_id', 'place_of_birth', 'vital_status', 'email', 'notes'] as $field) {
            if (array_key_exists($field, $data)) {
                $fill[$field] = is_string($data[$field]) ? (trim($data[$field]) === '' ? null : trim($data[$field])) : $data[$field];
            }
        }
        foreach (['phone', 'alt_phone'] as $field) {
            if (array_key_exists($field, $data)) {
                $fill[$field] = ($value = Identity::clean($data[$field])) === '' ? null : $value;
            }
        }
        $fill['dob_status'] ??= $person->dob_status ?? 'declared';
        $fill['vital_status'] ??= $person->vital_status ?? 'unknown';
        $person->fill($fill);

        $parts = array_filter([$person->first_name, $person->father_name, $person->grandfather_name, $person->family_name, $person->extra_names]);
        $given = trim((string) ($data['full_name'] ?? ''));
        $person->full_name = $given !== '' ? $given : ($parts !== [] ? implode(' ', $parts) : (string) $person->full_name);
        if ($person->full_name === '') {
            throw ValidationException::withMessages(['full_name' => 'اكتب الاسم الأول واسم العائلة على الأقل، أو الاسم الكامل.']);
        }
        foreach (self::NAME_PARTS as $field => $normalized) {
            $person->{$normalized} = ArabicText::normalize($person->{$field}) ?: null;
        }
        $person->name_search = ArabicText::normalize(implode(' ', array_filter([$person->full_name, $person->extra_names, $person->name_en])));

        if (array_key_exists('id_number', $data) || array_key_exists('id_type_value_id', $data)) {
            $type = array_key_exists('id_type_value_id', $data) ? ($data['id_type_value_id'] ? (int) $data['id_type_value_id'] : null) : $person->id_type_value_id;
            $raw = array_key_exists('id_number', $data) ? $data['id_number'] : $person->id_number;
            [$format, $clean] = Identity::classify($raw, $type);
            $changed = $clean !== (string) $person->id_number;
            $person->id_type_value_id = $type;
            $person->id_format = $format;
            $person->id_number = $clean === '' ? null : $clean;
            $person->id_hash = $clean === '' ? null : Identity::hash($clean);
            $person->id_last4 = $clean === '' ? null : mb_substr($clean, -4);
            if ($changed) {
                $person->id_verified_at = null;
                $person->id_verified_by = null;
                $person->id_verification_source = null;
            }
            if ($person->id_hash && in_array($format, ['valid_format', 'other_type'], true)) {
                $owner = Person::query()->where('id_hash', $person->id_hash)->whereIn('id_format', ['valid_format', 'other_type'])
                    ->when($person->exists, fn ($q) => $q->where('id', '<>', $person->id))->first(['id', 'full_name']);
                if ($owner) {
                    throw ValidationException::withMessages([$errorKey => 'رقم الهوية مسجل لشخص آخر في النظام («'.$owner->full_name.'»). اختر الشخص الموجود بدل إنشاء سجل مكرر.']);
                }
            }
        }

        return $person;
    }

    /**
     * Possible duplicates for review: exact identity matches (any format) and Arabic-name collisions. Nothing is merged.
     *
     * @return list<array{id: int, public_id: string, full_name: string, reason: string, id_masked: string, dob: ?string, roles: list<string>}>
     */
    public function duplicates(array $data, ?int $exceptId = null): array
    {
        $found = [];
        $clean = Identity::clean($data['id_number'] ?? null);
        if ($clean !== '') {
            foreach (Person::query()->where('id_hash', Identity::hash($clean))->when($exceptId, fn ($q) => $q->where('id', '<>', $exceptId))->limit(10)->get() as $person) {
                $found[$person->id] = [$person, Identity::isPlaceholder($clean) ? 'نفس رقم الهوية (رقم وهمي؛ لا يثبت أنه الشخص نفسه)' : 'نفس رقم الهوية'];
            }
        }
        $first = ArabicText::normalize($data['first_name'] ?? null);
        $family = ArabicText::normalize($data['family_name'] ?? null);
        if ($first !== '' && $family !== '') {
            $query = Person::query()->where('n_first', $first)->where('n_family', $family)->when($exceptId, fn ($q) => $q->where('id', '<>', $exceptId));
            foreach (['father_name' => 'n_father', 'grandfather_name' => 'n_grandfather'] as $field => $column) {
                if (($value = ArabicText::normalize($data[$field] ?? null)) !== '') {
                    $query->where(fn ($q) => $q->where($column, $value)->orWhereNull($column));
                }
            }
            foreach ($query->limit(10)->get() as $person) {
                $found[$person->id] ??= [$person, 'تشابه في الاسم'.($person->date_of_birth && ! empty($data['date_of_birth']) && $person->date_of_birth->toDateString() === $data['date_of_birth'] ? ' وتاريخ الميلاد' : '')];
            }
        }

        return array_values(array_map(function (array $entry): array {
            [$person, $reason] = $entry;
            $roles = [];
            if (DB::table('orphans')->where('person_id', $person->id)->exists()) {
                $roles[] = 'يتيم';
            }
            if (DB::table('orphan_guardians')->where('person_id', $person->id)->exists()) {
                $roles[] = 'وصي';
            }
            if (DB::table('orphan_parent_links')->where('person_id', $person->id)->exists()) {
                $roles[] = 'أب/أم ليتيم';
            }

            return ['id' => (int) $person->id, 'public_id' => $person->public_id, 'full_name' => $person->full_name, 'reason' => $reason,
                'id_masked' => $person->maskedId(), 'dob' => $person->date_of_birth?->toDateString(), 'roles' => $roles];
        }, $found));
    }

    /** Records a dated life-status event (death, missing, alive) and moves the person's current status with it. */
    /**
     * $move = false keeps the current status as it is and only adds the reported event to the history, for
     * example when a new request reports a different status for an already-registered person.
     */
    public function recordStatus(Person $person, array $data, int $actor, bool $move = true): void
    {
        DB::table('person_status_events')->insert([
            'person_id' => $person->id, 'status' => $data['status'], 'event_date' => $data['event_date'] ?? null, 'place' => $data['place'] ?? null,
            'cause' => filled($data['cause'] ?? null) ? encrypt($data['cause']) : null, 'evidence_note' => $data['evidence_note'] ?? null,
            'cause_value_id' => $data['cause_value_id'] ?? null, 'certificate_no' => $data['certificate_no'] ?? null, 'reported_on' => $data['reported_on'] ?? null,
            'reported_to' => $data['reported_to'] ?? null, 'report_ref' => $data['report_ref'] ?? null, 'source' => $data['source'] ?? null,
            'confidence' => $data['confidence'] ?? null, 'official_cause' => filled($data['official_cause'] ?? null) ? encrypt($data['official_cause']) : null,
            'verification' => $data['verification'] ?? 'reported', 'verified_by' => ($data['verification'] ?? '') === 'verified' ? $actor : null,
            'verified_at' => ($data['verification'] ?? '') === 'verified' ? now() : null, 'recorded_by' => $actor, 'created_at' => now(),
        ]);
        if ($move) {
            $person->forceFill(['vital_status' => $data['status'], 'updated_by' => $actor])->save();
        }
    }

    /** Marks the document identity as checked by a person against a source (manual; no civil-registry API exists). */
    public function verifyIdentity(Person $person, string $source, int $actor): void
    {
        abort_if(! in_array($person->id_format, ['valid_format', 'other_type'], true), 422, 'لا يمكن توثيق رقم هوية غير صحيح الصيغة أو وهمي.');
        $person->forceFill(['id_verified_at' => now(), 'id_verified_by' => $actor, 'id_verification_source' => $source, 'updated_by' => $actor])->save();
    }
}
