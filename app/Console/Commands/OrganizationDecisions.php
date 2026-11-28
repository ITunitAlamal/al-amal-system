<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Organization\Authority;
use App\Services\Organization\OrganizationStructure;
use App\Services\Organization\StructureChanges;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Applies structural decisions the institution already took (owner or board) from a JSON file, after a dry run.
 * Without --apply nothing is written: every decision is validated inside a transaction that is rolled back and the
 * blockers and effects are printed. Decisions already in effect are skipped, so the file can be run again safely.
 * --actor is the operator who executes the file (stored as executed_by in the ledger details and the audit row); he is
 * recorded as the decider only for authority "executive_director", and only when he is the executive director in office.
 *
 * File: {"authority": "owner_decision", "reference": "…", "decisions": [{"type": "retire", "code": "X", "reason": "…"},
 * {"type": "convert_to_unit", "code": "X", "parent": "HR", "name": "…", "reason": "…"}, {"type": "create_unit", "parent": "FIN", "name": "…", "reason": "…"}]}
 */
#[Signature('org:structure-decisions {file : JSON file of approved decisions} {--actor= : user id recorded as executing the decisions} {--apply : write the changes} {--suggest : also add the suggested units for review}')]
#[Description('Dry-run or apply approved organizational decisions (archive, convert to unit, create unit, rename, move)')]
final class OrganizationDecisions extends Command
{
    /** Authorities a decision taken outside the workflow may cite (decided_by is set only for the executive director himself). */
    private const AUTHORITIES = ['executive_director', 'board_decision', 'owner_decision'];

    public function handle(OrganizationStructure $structure, StructureChanges $changes): int
    {
        $file = json_decode((string) @file_get_contents((string) $this->argument('file')), true);
        if (! is_array($file) || ! is_array($file['decisions'] ?? null) || empty($file['authority']) || empty($file['reference'])) {
            $this->error('ملف القرارات غير صالح: يلزم authority وreference وdecisions.');

            return self::FAILURE;
        }
        $authority = $file['authority'];
        if (! in_array($authority, self::AUTHORITIES, true) || ! is_string($file['reference']) || trim($file['reference']) === '') {
            $this->error('صفة القرار غير مقبولة: يلزم authority من ('.implode('، ', self::AUTHORITIES).') ومرجع القرار reference.');

            return self::FAILURE;
        }
        $actor = User::find((int) $this->option('actor'));
        if (! $actor || ! $actor->is_active) {
            $this->error('حدد --actor بمعرّف مستخدم نشط يُسجَّل منفذًا للقرارات.');

            return self::FAILURE;
        }
        // The operator executes; he is the decider only when he is the executive director in office deciding himself.
        // An owner or Board decision is recorded by its authority and reference with no decider (decided_by stays empty).
        if ($authority === 'executive_director' && ! app(Authority::class)->isExecutive($actor)) {
            $this->error('قرار بصفة المدير التنفيذي ينفذه المدير التنفيذي المعيّن نفسه، أو يُعتمد من مسار «تعديل الهيكل».');

            return self::FAILURE;
        }
        $deciderId = $authority === 'executive_director' ? (int) $actor->id : null;
        $apply = (bool) $this->option('apply');
        $failed = 0;
        DB::beginTransaction();
        try {
            foreach ($file['decisions'] as $index => $decision) {
                $label = ($index + 1).'. '.($decision['type'] ?? '?').' '.($decision['code'] ?? $decision['name'] ?? '');
                try {
                    $row = $this->toChange($decision);
                    if ($row === null) {
                        $this->line("{$label}: مطبَّق من قبل — تخطٍّ");

                        continue;
                    }
                    $id = $structure->record([...$row, 'rationale' => trim(($decision['reason'] ?? '').' — '.$file['reference']), 'details' => ['reference' => $file['reference']]], $actor->id, 'in_review');
                    $structure->apply(DB::table('department_structure_changes')->find($id), $deciderId, $authority, null, (int) $actor->id);
                    $after = DB::table('department_structure_changes')->where('id', $id)->value('department_id');
                    $node = DB::table('departments')->where('id', $after)->first(['code', 'name', 'node_type', 'parent_id', 'is_active', 'deleted_at']);
                    $this->info("{$label}: صالح → {$node->code} «{$node->name}» ".$node->node_type.($node->deleted_at ? ' (مؤرشف)' : ($node->is_active ? ' (مفعّل)' : ' (معطّل)')));
                } catch (ValidationException $exception) {
                    $failed++;
                    $this->error("{$label}: مرفوض — ".implode(' ', $exception->validator->errors()->all()));
                }
            }
            if ($this->option('suggest')) {
                $this->line('اقتراحات وحدات للمراجعة: '.$changes->suggestDefaults());
            }
            if ($apply && $failed === 0) {
                DB::commit();
                $this->info('طُبّقت القرارات وسُجّلت في سجل تعديلات الهيكل.');
            } else {
                DB::rollBack();
                $this->warn($apply ? "لم يُطبَّق شيء: {$failed} قرار مرفوض." : 'تشغيل تجريبي: لم يُكتب شيء. أعد التشغيل مع --apply للتطبيق.');
            }
        } catch (Throwable $exception) {
            DB::rollBack();
            $this->error('توقف دون تطبيق: '.$exception->getMessage());

            return self::FAILURE;
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The ledger fields for one decision, or null when it is already in effect.
     *
     * @param  array<string, mixed>  $decision
     * @return array<string, mixed>|null
     */
    private function toChange(array $decision): ?array
    {
        $node = isset($decision['code']) ? DB::table('departments')->where('code', $decision['code'])->first() : null;
        $parent = isset($decision['parent']) ? DB::table('departments')->where('code', $decision['parent'])->whereNull('deleted_at')->first() : null;
        $missing = fn (string $what) => throw ValidationException::withMessages(['decision' => "{$what} غير موجود."]);

        return match ($decision['type'] ?? null) {
            'retire' => $node === null ? $missing('القسم '.($decision['code'] ?? '')) : ($node->deleted_at !== null ? null : ['change_type' => 'retire', 'department_id' => $node->id]),
            'convert_to_unit' => $node === null || $parent === null ? $missing('القسم أو الجهة التابع لها')
                : ($node->node_type === 'unit' && (int) $node->parent_id === (int) $parent->id ? null : ['change_type' => 'convert_to_unit', 'department_id' => $node->id, 'parent_id' => $parent->id, 'proposed_name' => $decision['name'] ?? null]),
            'create_unit' => $parent === null ? $missing('الجهة التابع لها')
                : (DB::table('departments')->where('parent_id', $parent->id)->whereNull('deleted_at')->whereRaw('lower(btrim(name)) = lower(btrim(?))', [$decision['name'] ?? ''])->exists() ? null
                    : ['change_type' => 'create_unit', 'parent_id' => $parent->id, 'proposed_name' => $decision['name'] ?? null, 'proposed_code' => $decision['new_code'] ?? null]),
            'rename' => $node === null ? $missing('القسم') : ($node->name === ($decision['name'] ?? null) ? null : ['change_type' => 'rename', 'department_id' => $node->id, 'proposed_name' => $decision['name'] ?? null]),
            'reparent' => $node === null ? $missing('القسم') : ((int) $node->parent_id === (int) ($parent->id ?? 0) ? null : ['change_type' => 'reparent', 'department_id' => $node->id, 'parent_id' => $parent->id ?? null]),
            default => throw ValidationException::withMessages(['decision' => 'نوع قرار غير معروف.']),
        };
    }
}
