<?php

namespace App\Services\Orphans\Import;

use App\Models\User;
use App\Services\AuditService;
use App\Services\Orphans\OrphanAccess;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

final class RegistryCorrections
{
    public function __construct(private readonly OrphanAccess $access, private readonly AuditService $audit) {}

    public function available(): bool
    {
        return Schema::hasColumn('orphan_import_rows', 'corrections_payload');
    }

    /** @return array<string, array> */
    public function fields(User $user): array
    {
        if (! $this->access->allows($user, 'records')) {
            return [];
        }
        $sensitive = $this->access->allows($user, 'sensitive');

        return array_filter(RegistrySheet::COLUMNS, fn (array $column): bool => ! in_array($column[2], ['datetime', 'serial', 'age_snapshot', 'raw', 'reference'], true)
            && ($sensitive || (! $column[3] && $column[2] !== 'national_id')));
    }

    /** @return array<string, string> */
    public static function values(object $row): array
    {
        $original = json_decode(Crypt::decryptString($row->raw_payload), true, flags: JSON_THROW_ON_ERROR);
        $corrections = empty($row->corrections_payload) ? [] : json_decode(Crypt::decryptString($row->corrections_payload), true, flags: JSON_THROW_ON_ERROR);

        return array_replace($original, $corrections);
    }

    /** @param array<string, mixed> $input */
    public function update(User $user, int $id, array $input): void
    {
        $this->access->assert($user, 'view');
        $this->access->assert($user, 'records');
        abort_unless($this->available(), 503, 'التعديل ينتظر تفعيل تحديث قاعدة البيانات.');
        $fields = $this->fields($user);
        $rules = ['version' => ['required', 'integer', 'min:0'], 'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'changes' => ['required', 'array:'.implode(',', array_keys($fields)), 'min:1']];
        foreach ($fields as $key => $column) {
            $rules['changes.'.$key] = ['sometimes', 'nullable', 'string', 'max:2000'];
            if (in_array($column[2], ['name', 'lookup'], true)) {
                $rules['changes.'.$key][] = $column[2] === 'name' ? 'max:255' : 'max:120';
            }
            if ($column[2] === 'national_id') {
                $rules['changes.'.$key][] = 'regex:/^[0-9]{9}$/';
            } elseif ($column[2] === 'date') {
                $rules['changes.'.$key][] = 'date_format:Y-m-d';
                $rules['changes.'.$key][] = 'before_or_equal:today';
            } elseif ($column[2] === 'count') {
                $rules['changes.'.$key][] = 'regex:/^[0-9]{1,3}$/';
            } elseif ($column[2] === 'sex') {
                $rules['changes.'.$key][] = 'in:ذكر,أنثى';
            } elseif ($key === 'is_displaced') {
                $rules['changes.'.$key][] = 'in:نعم,لا';
            }
        }
        $data = Validator::make($input, $rules)->validate();
        DB::transaction(function () use ($user, $id, $data): void {
            $row = DB::table('orphan_import_rows')->lockForUpdate()->find($id);
            abort_unless($row, 404);
            abort_unless(DB::table('orphan_import_batches')->where('id', $row->batch_id)->where('status', 'staged')->exists(), 409, 'الدفعة غير جاهزة للتعديل.');
            abort_unless((int) $row->correction_version === (int) $data['version'], 409, 'عُدّل السجل بواسطة مستخدم آخر. أعد فتحه قبل الحفظ.');
            $before = self::values($row);
            $changes = array_filter(array_map(fn ($value): string => trim((string) $value), $data['changes']), fn (string $value, string $key): bool => $value !== ($before[$key] ?? ''), ARRAY_FILTER_USE_BOTH);
            if ($changes === []) {
                return;
            }
            $cells = [];
            $map = [];
            foreach (array_replace($before, $changes) as $key => $value) {
                $map[$key] = count($cells);
                $cells[] = ['value' => $value, 'kind' => 'string', 'date' => null];
            }
            $normalized = (new RegistryRowNormalizer)->normalize($cells, $map);
            $updates = [];
            $flags = json_decode((string) $row->parse_flags, true) ?: [];
            foreach ($changes as $key => $value) {
                $type = RegistrySheet::COLUMNS[$key][2];
                $column = $type === 'lookup' && $key !== 'is_displaced' ? $key.'_key' : $key;
                if (property_exists($row, $column)) {
                    $updates[$column] = $normalized['values'][$column];
                }
                if ($type === 'name') {
                    $updates[$key.'_search'] = $normalized['values'][$key.'_search'];
                }
                if ($type === 'national_id') {
                    $updates[str_replace('_national_id', '_id_status', $key)] = $normalized['values'][$key.'_status'];
                }
                unset($flags[$key]);
                if (isset($normalized['flags'][$key])) {
                    $flags[$key] = $normalized['flags'][$key];
                }
            }
            $effective = (object) array_replace((array) $row, $updates);
            foreach (['child', 'guardian'] as $kind) {
                $updates[$kind.'_key'] = $effective->{$kind.'_id_status'} === 'format_ok' ? $effective->{$kind.'_national_id'} : null;
            }
            $updates['household_key'] = $effective->father_id_status === 'format_ok' && $effective->mother_id_status === 'format_ok'
                ? $effective->father_national_id.'|'.$effective->mother_national_id : null;
            $updates['guardian_claims_mother'] = in_array($effective->guardian_relation_key, RegistryRowNormalizer::MOTHER_RELATIONS, true);
            $prior = empty($row->corrections_payload) ? [] : json_decode(Crypt::decryptString($row->corrections_payload), true);
            $updates['corrections_payload'] = Crypt::encryptString(json_encode(array_replace($prior, $changes), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $updates['correction_version'] = (int) $row->correction_version + 1;
            $updates['parse_flags'] = json_encode($flags, JSON_THROW_ON_ERROR);
            $updates['review_status'] = 'needs_followup';
            $updates['updated_at'] = now()->format('Y-m-d H:i:s.u');
            foreach (['identity', 'household', 'guardianship'] as $kind) {
                foreach (['decision', 'note', 'decided_by', 'decided_at'] as $suffix) {
                    $updates[$kind.'_'.$suffix] = null;
                }
            }
            DB::table('orphan_import_rows')->where('id', $id)->update($updates);
            $this->audit->recordRow('orphans.import.corrected', 'orphan_import_rows', $id,
                ['encrypted_values' => Crypt::encryptString(json_encode(array_intersect_key($before, $changes), JSON_UNESCAPED_UNICODE))],
                ['encrypted_values' => Crypt::encryptString(json_encode($changes, JSON_UNESCAPED_UNICODE)), 'fields' => array_keys($changes),
                    'encrypted_reason' => Crypt::encryptString($data['reason']), 'version' => $updates['correction_version']], (int) $user->id, request()?->ip());
        });
    }
}
