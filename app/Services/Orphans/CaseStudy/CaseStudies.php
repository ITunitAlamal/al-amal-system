<?php

namespace App\Services\Orphans\CaseStudy;

use App\Models\User;
use App\Services\AuditService;
use App\Services\Orphans\OrphanAccess;
use App\Services\Orphans\Registration\RegistrationFieldSpec;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The household case study («دراسة الحالة»): a stage separate from registration. Registration never waits for it, and
 * it decides nothing by itself (no score approves a benefit).
 *
 * - Department staff fill it as a draft (nothing required), then submit it. The questionnaire's submission rules apply
 *   only then.
 * - The head reviews it or returns it with a note; the reviewer is never the assessor.
 * - Psychological and health answers are encrypted apart and readable only with the «health» capability. Without it
 *   they are neither shown nor changed by a save.
 * - The audit records who did what and when, never an answer.
 */
final class CaseStudies
{
    public const STATUS = ['draft' => 'مسودة', 'submitted' => 'بانتظار المراجعة', 'returned' => 'معادة للاستكمال', 'reviewed' => 'مراجَعة'];

    private const MAX_ROWS = 30;

    public function __construct(private readonly OrphanAccess $access, private readonly AuditService $audit) {}

    public function household(string $publicId): object
    {
        return DB::table('households')->where('public_id', $publicId)->first(['id', 'public_id', 'reference_no', 'display_name', 'status']) ?? abort(404);
    }

    public function find(string $publicId): object
    {
        return DB::table('household_case_studies')->where('public_id', $publicId)->first() ?? abort(404);
    }

    /** The open study of the household, else its latest reviewed one. */
    public function current(int $householdId): ?object
    {
        return DB::table('household_case_studies')->where('household_id', $householdId)->orderByRaw("status = 'reviewed'")->orderByDesc('id')->first();
    }

    /** Starts a draft unless one is already open (one open study per household, enforced by a unique index too). */
    public function open(object $household, User $user): object
    {
        $this->access->assertEdit($user);

        return DB::transaction(function () use ($household, $user): object {
            DB::table('households')->where('id', $household->id)->lockForUpdate()->first();
            $open = DB::table('household_case_studies')->where('household_id', $household->id)->where('status', '<>', 'reviewed')->first();
            if ($open) {
                return $open;
            }
            $id = DB::table('household_case_studies')->insertGetId(['public_id' => (string) Str::ulid(), 'household_id' => $household->id, 'schema_version' => CaseStudySchema::CURRENT,
                'status' => 'draft', 'assessor_id' => $user->id, 'created_by' => $user->id, 'updated_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->record('opened', $id, $household, $user);

            return DB::table('household_case_studies')->where('id', $id)->first();
        });
    }

    /** Saves the answers of an open study (draft or returned): types are checked, nothing is required. */
    public function save(object $study, array $input, int $lockVersion, User $user, bool $submit = false): object
    {
        $this->access->assertEdit($user);
        $mayHealth = $this->access->allows($user, 'health');

        return DB::transaction(function () use ($study, $input, $lockVersion, $user, $mayHealth, $submit): object {
            $row = DB::table('household_case_studies')->where('id', $study->id)->lockForUpdate()->first();
            abort_unless(in_array($row->status, ['draft', 'returned'], true), 422, 'الدراسة مرسلة أو مراجَعة؛ لا تُعدل الآن.');
            abort_if((int) $row->lock_version !== $lockVersion, 409, 'حُفظت نسخة أحدث من هذه الدراسة في نافذة أخرى. أعد تحميل الصفحة.');
            $schema = CaseStudySchema::version((int) $row->schema_version);
            $answers = $this->clean($schema, (array) ($input['answers'] ?? []), $mayHealth);
            $previous = $this->answers($row, true);
            // Without the health capability the restricted sections stay exactly as saved before.
            foreach ($schema as $section => $def) {
                if ($def['sensitive'] && ! $mayHealth) {
                    $answers[$section] = $previous[$section] ?? [];
                }
            }
            $assessedOn = $input['assessed_on'] ?? $row->assessed_on;
            Validator::make(['assessed_on' => $assessedOn], ['assessed_on' => ['nullable', 'date', 'before_or_equal:today']], [], ['assessed_on' => 'تاريخ الدراسة'])->validate();
            if ($submit) {
                $answers = $this->withoutHidden($schema, $answers);
                $errors = $this->missing($schema, $answers);
                if (blank($assessedOn)) {
                    $errors['assessed_on'] = 'اكتب تاريخ الدراسة.';
                }
                if ($errors !== []) {
                    throw ValidationException::withMessages($errors);
                }
            }
            [$general, $sensitive] = $this->split($schema, $answers);
            DB::table('household_case_studies')->where('id', $row->id)->update([
                'answers' => $general === [] ? null : encrypt(json_encode($general, JSON_UNESCAPED_UNICODE)),
                'sensitive_answers' => $sensitive === [] ? null : encrypt(json_encode($sensitive, JSON_UNESCAPED_UNICODE)),
                'assessed_on' => $assessedOn ?: null, 'lock_version' => $row->lock_version + 1, 'updated_by' => $user->id, 'updated_at' => now(),
                ...($submit ? ['status' => 'submitted', 'submitted_at' => now(), 'assessor_id' => $user->id, 'review_note' => null] : []),
            ]);
            $this->record($submit ? 'submitted' : 'saved', $row->id, $this->household(DB::table('households')->where('id', $row->household_id)->value('public_id')), $user);

            return DB::table('household_case_studies')->where('id', $row->id)->first();
        });
    }

    /** The head reviews a submitted study (never their own) or returns it with a note for completion. */
    public function decide(object $study, string $decision, ?string $note, User $user): object
    {
        abort_unless($this->access->isHead($user), 403, 'مراجعة دراسة الحالة لرئيس القسم.');
        abort_unless(in_array($decision, ['review', 'return'], true), 422);

        return DB::transaction(function () use ($study, $decision, $note, $user): object {
            $row = DB::table('household_case_studies')->where('id', $study->id)->lockForUpdate()->first();
            abort_unless($row->status === 'submitted', 422, 'الدراسة ليست بانتظار المراجعة.');
            abort_if((int) $row->assessor_id === (int) $user->id, 422, 'لا يراجع الباحث دراسته؛ يراجعها رئيس القسم أو من يقوم مقامه.');
            if ($decision === 'return' && blank($note)) {
                throw ValidationException::withMessages(['note' => 'اكتب ما يلزم استكماله.']);
            }
            DB::table('household_case_studies')->where('id', $row->id)->update([
                'status' => $decision === 'review' ? 'reviewed' : 'returned', 'review_note' => $note ? mb_substr(trim($note), 0, 2000) : null,
                ...($decision === 'review' ? ['reviewed_by' => $user->id, 'reviewed_at' => now()] : []), 'lock_version' => $row->lock_version + 1, 'updated_at' => now(),
            ]);
            $this->record($decision === 'review' ? 'reviewed' : 'returned', $row->id, $this->household(DB::table('households')->where('id', $row->household_id)->value('public_id')), $user);

            return DB::table('household_case_studies')->where('id', $row->id)->first();
        });
    }

    /** Decrypted answers; the restricted sections only when $sensitive. */
    public function answers(object $study, bool $sensitive): array
    {
        $read = fn (?string $value): array => $value ? (json_decode(decrypt($value), true) ?: []) : [];

        return $read($study->answers) + ($sensitive ? $read($study->sensitive_answers) : []);
    }

    /** Members of the household for the «الاسم» columns: [key => public_id, name]. */
    public function persons(int $householdId): array
    {
        return DB::table('household_members as m')->join('persons as p', 'p.id', '=', 'm.person_id')->where('m.household_id', $householdId)->whereNull('m.ends_on')
            ->orderBy('p.date_of_birth')->get(['p.public_id', 'p.full_name'])->map(fn ($p): array => ['key' => $p->public_id, 'name' => $p->full_name])->all();
    }

    /** Lists of the version (active values, plus inactive ones the answers still use, marked). */
    public function options(int $version, array $answers): array
    {
        $types = CaseStudySchema::listTypes($version);
        $used = [];
        array_walk_recursive($answers, function ($v) use (&$used): void {
            if (is_int($v)) {
                $used[] = $v;
            }
        });
        $rows = DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')->whereIn('t.code', $types)
            ->where(fn ($q) => $q->where('v.is_active', true)->orWhereIn('v.id', $used ?: [0]))->orderBy('v.sort_order')->orderBy('v.name_ar')
            ->get(['v.id', 'v.name_ar', 'v.code', 'v.is_active', 't.code as type']);
        $out = array_fill_keys($types, []);
        foreach ($rows as $v) {
            $out[$v->type][] = ['id' => (int) $v->id, 'name' => $v->name_ar.($v->is_active ? '' : ' (قيمة موقوفة)'), 'code' => $v->code];
        }

        return $out;
    }

    /** Required answers missing on submission. @return array<string, string> */
    public function missing(array $schema, array $answers): array
    {
        $errors = [];
        foreach ($schema as $section => $def) {
            foreach ($def['questions'] as $q) {
                $path = $section.'.'.$q['key'];
                if (isset($q['show_if']) && ! RegistrationFieldSpec::holds($q['show_if'], $answers, null)) {
                    continue;
                }
                $value = data_get($answers, $path);
                $required = ($q['required'] ?? false) === true || (is_array($q['required'] ?? null) && RegistrationFieldSpec::holds($q['required'], $answers, null));
                if ($required && ! self::answered($q, $value)) {
                    $errors['answers.'.$path] = $q['type'] === 'repeat' ? 'أضف صفًا واحدًا على الأقل: '.$q['label'].'.' : 'مطلوب عند الإرسال: '.$q['label'].'.';
                }
                foreach ($this->otherGaps($q, $value, $answers, $path) as $key => $message) {
                    $errors['answers.'.$key] = $message;
                }
            }
        }

        return $errors;
    }

    /** Validates types and keeps only the questionnaire's keys (unknown keys are dropped). */
    private function clean(array $schema, array $input, bool $mayHealth): array
    {
        $rules = [];
        $attributes = [];
        foreach ($schema as $section => $def) {
            if ($def['sensitive'] && ! $mayHealth) {
                continue;
            }
            foreach ($def['questions'] as $q) {
                $this->rulesFor($q, 'answers.'.$section.'.'.$q['key'], $rules, $attributes);
            }
        }
        Validator::make(['answers' => $input], $rules, [], $attributes)->validate();

        $out = [];
        foreach ($schema as $section => $def) {
            if ($def['sensitive'] && ! $mayHealth) {
                continue;
            }
            foreach ($def['questions'] as $q) {
                $value = $this->cast($q, $input[$section][$q['key']] ?? null);
                if ($value !== null) {
                    $out[$section][$q['key']] = $value;
                }
                if (! empty($q['other']) && filled($other = $input[$section][$q['key'].'_other'] ?? null)) {
                    $out[$section][$q['key'].'_other'] = mb_substr(trim((string) $other), 0, 200);
                }
            }
        }

        return $out;
    }

    private function rulesFor(array $q, string $path, array &$rules, array &$attributes): void
    {
        $attributes[$path] = $q['label'];
        $listRule = fn (string $type): array => ['integer', Rule::exists('master_data_values', 'id')->where('type_id', (int) DB::table('master_data_types')->where('code', $type)->value('id'))];
        $rules[$path] = match ($q['type']) {
            'single' => ['nullable', ...$listRule($q['list'])],
            'multi' => ['nullable', 'array', 'max:30'],
            'choice' => ['nullable', Rule::in(array_keys($q['options']))],
            'text', 'person' => ['nullable', 'string', 'max:300'],
            'textarea' => ['nullable', 'string', 'max:3000'],
            'money' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'number' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'date' => ['nullable', 'date', 'before_or_equal:today'],
            'repeat' => ['nullable', 'array', 'max:'.self::MAX_ROWS],
        };
        if ($q['type'] === 'multi') {
            $rules[$path.'.*'] = ['distinct', ...$listRule($q['list'])];
        }
        if (! empty($q['other'])) {
            $rules[$path.'_other'] = ['nullable', 'string', 'max:200'];
        }
        if ($q['type'] === 'repeat') {
            foreach ($q['columns'] as $column) {
                $this->rulesFor($column, $path.'.*.'.$column['key'], $rules, $attributes);
            }
        }
    }

    private function cast(array $q, mixed $value): mixed
    {
        $blank = $value === null || $value === '' || $value === [];

        return match ($q['type']) {
            'single', 'number' => $blank ? null : (int) $value,
            'multi' => $blank ? null : array_values(array_map('intval', (array) $value)),
            'money' => $blank ? null : round((float) $value, 2),
            'repeat' => $blank ? null : (array_values(array_filter(array_map(function ($row) use ($q): ?array {
                $out = [];
                foreach ($q['columns'] as $column) {
                    if (($v = $this->cast($column, $row[$column['key']] ?? null)) !== null) {
                        $out[$column['key']] = $v;
                    }
                    if (! empty($column['other']) && filled($other = $row[$column['key'].'_other'] ?? null)) {
                        $out[$column['key'].'_other'] = mb_substr(trim((string) $other), 0, 200);
                    }
                }

                return $out === [] ? null : $out;
            }, array_values((array) $value)))) ?: null),
            default => $blank ? null : trim((string) $value),
        };
    }

    /** On submission, answers to questions that are no longer shown are dropped (e.g. rows after «لا»). */
    private function withoutHidden(array $schema, array $answers): array
    {
        foreach ($schema as $section => $def) {
            foreach ($def['questions'] as $q) {
                if (isset($q['show_if']) && ! RegistrationFieldSpec::holds($q['show_if'], $answers, null)) {
                    unset($answers[$section][$q['key']], $answers[$section][$q['key'].'_other']);
                }
            }
        }

        return $answers;
    }

    /** «أخرى» chosen without an explanation. @return array<string, string> */
    private function otherGaps(array $q, mixed $value, array $answers, string $path): array
    {
        $gaps = [];
        if (! empty($q['other']) && $this->choseOther($q, $value) && blank(data_get($answers, $path.'_other'))) {
            $gaps[$path.'_other'] = 'اختيار «أخرى» يحتاج توضيحًا: '.$q['label'].'.';
        }
        if ($q['type'] === 'repeat') {
            foreach (array_values((array) $value) as $i => $row) {
                foreach ($q['columns'] as $column) {
                    if (! empty($column['other']) && $this->choseOther($column, $row[$column['key']] ?? null) && blank($row[$column['key'].'_other'] ?? null)) {
                        $gaps[$path.'.'.$i.'.'.$column['key'].'_other'] = 'اختيار «أخرى» يحتاج توضيحًا: '.$column['label'].' (الصف '.($i + 1).').';
                    }
                }
            }
        }

        return $gaps;
    }

    private function choseOther(array $q, mixed $value): bool
    {
        $ids = array_filter(array_map('intval', (array) $value));

        return $ids !== [] && DB::table('master_data_values')->whereIn('id', $ids)->where('code', 'other')->exists();
    }

    private static function answered(array $q, mixed $value): bool
    {
        return $q['type'] === 'repeat' ? is_array($value) && $value !== [] : ! ($value === null || $value === '' || $value === []);
    }

    /** @return array{0: array, 1: array} general and restricted sections */
    private function split(array $schema, array $answers): array
    {
        $general = [];
        $sensitive = [];
        foreach ($schema as $section => $def) {
            if (empty($answers[$section])) {
                continue;
            }
            if ($def['sensitive']) {
                $sensitive[$section] = $answers[$section];
            } else {
                $general[$section] = $answers[$section];
            }
        }

        return [$general, $sensitive];
    }

    private function record(string $action, int $id, object $household, User $user): void
    {
        $this->audit->recordRow('orphans.case_study.'.$action, 'household_case_studies', $id, null, ['household' => $household->reference_no], $user->id, request()?->ip());
    }
}
