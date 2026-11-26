<?php

namespace App\Services\Orphans;

use App\Models\Department;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class OrphanDelegationService
{
    public function __construct(private readonly OrphanAccess $access) {}

    /** @param array{user_id: int, operation: string, capabilities: list<string>, reason: string} $data */
    public function request(User $head, array $data): int
    {
        return DB::transaction(function () use ($head, $data): int {
            $department = Department::where('code', 'ORPHANS')->where('is_active', true)->lockForUpdate()->first();
            abort_unless($department && $this->access->isHead($head, $department), 403);
            $target = User::findOrFail($data['user_id']);
            if (! $target->is_active || (int) $target->department_id !== (int) $department->id) {
                throw ValidationException::withMessages(['user_id' => 'اختر موظفًا نشطًا تابعًا لقسم الكفالات والأيتام.']);
            }
            $capabilities = array_values(array_unique($data['capabilities']));
            if (! in_array($data['operation'], ['grant', 'revoke'], true) || $capabilities === [] || array_diff($capabilities, array_keys(OrphanAccess::CAPABILITIES))) {
                throw ValidationException::withMessages(['capabilities' => 'صلاحيات أو عملية غير معروفة.']);
            }
            if ($data['operation'] === 'grant' && ! in_array('view', $capabilities, true)) {
                throw ValidationException::withMessages(['capabilities' => 'أرفق صلاحية العرض بطلب التفويض.']);
            }
            if (DB::table('orphan_access_requests')->where('department_id', $department->id)->where('user_id', $target->id)->where('status', 'pending')->exists()) {
                throw ValidationException::withMessages(['user_id' => 'يوجد طلب تفويض معلق لهذا الموظف؛ انتظر البت فيه.']);
            }

            return DB::table('orphan_access_requests')->insertGetId([
                'department_id' => $department->id, 'user_id' => $target->id, 'requested_by' => $head->id,
                'operation' => $data['operation'], 'capabilities' => json_encode($capabilities, JSON_THROW_ON_ERROR),
                'reason' => $data['reason'], 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    public function decide(User $admin, int $requestId, string $decision, ?string $note): void
    {
        abort_unless($admin->isSystemAdmin(), 403);
        abort_unless(in_array($decision, ['implement', 'reject'], true), 422);
        DB::transaction(function () use ($admin, $requestId, $decision, $note): void {
            $record = DB::table('orphan_access_requests')->find($requestId);
            abort_unless($record, 404);
            $department = Department::whereKey($record->department_id)->lockForUpdate()->first();
            $record = DB::table('orphan_access_requests')->where('id', $requestId)->lockForUpdate()->first();
            if ($record->status !== 'pending') {
                throw ValidationException::withMessages(['request' => 'تم البت في هذا الطلب مسبقًا.']);
            }
            abort_if((int) $record->requested_by === (int) $admin->id, 403, 'يجب فصل مقدم الطلب عن منفذ التفويض.');
            if ($decision === 'implement') {
                $head = User::find($record->requested_by);
                $target = User::whereKey($record->user_id)->lockForUpdate()->first();
                if (! $department || ! $department->is_active || ! $head || ! $this->access->isHead($head, $department)
                    || ! $target || ! $target->is_active || (int) $target->department_id !== (int) $department->id) {
                    throw ValidationException::withMessages(['request' => 'تغير رئيس القسم أو تبعية الموظف أو حالة التفعيل؛ يلزم طلب جديد من الرئيس الحالي.']);
                }
                $capabilities = json_decode($record->capabilities, true, flags: JSON_THROW_ON_ERROR);
                if ($record->operation === 'revoke' && in_array('view', $capabilities, true)) {
                    $capabilities = array_keys(OrphanAccess::CAPABILITIES);
                }
                foreach ($capabilities as $capability) {
                    DB::table('orphan_access_grants')->updateOrInsert([
                        'department_id' => $department->id, 'user_id' => $target->id, 'capability' => $capability,
                    ], [
                        'is_active' => $record->operation === 'grant', 'access_request_id' => $record->id,
                        'executed_by' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
            DB::table('orphan_access_requests')->where('id', $record->id)->update([
                'status' => $decision === 'implement' ? 'implemented' : 'rejected',
                'handled_by' => $admin->id, 'handled_at' => now(), 'decision_note' => $note, 'updated_at' => now(),
            ]);
        });
    }
}
