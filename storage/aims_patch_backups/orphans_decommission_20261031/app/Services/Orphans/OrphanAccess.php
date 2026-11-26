<?php

namespace App\Services\Orphans;

use App\Models\Department;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Who may do what in the Sponsorships & Orphans department.
 *
 * - Staff act only through explicit capabilities requested by the head and implemented by the system admin
 *   (orphan_access_grants); a hidden link is never the control.
 * - The head (or the acting head inside a valid appointment) holds every capability of the department.
 * - The executive director reads files to decide on them, but does not edit records.
 * - The system admin has full access by owner decision, every read of a file is logged, and the admin is never
 *   the decision maker of an administrative approval.
 */
final class OrphanAccess
{
    public const CAPABILITIES = [
        'view' => 'عرض الملفات والطلبات',
        'records' => 'إدخال وتحديث ملفات الأسر والأيتام',
        'sensitive' => 'الاطلاع على البيانات الصحية والمالية والهويات كاملة',
        'verify' => 'التحقق من الأهلية والمستندات',
        'review' => 'المراجعة وإعادة الاستكمال',
        'approve' => 'اعتماد الوصي (مسار السجل المدني)',
        'reject' => 'رفض الطلب',
        'visits' => 'الزيارات والبحث الاجتماعي',
        'sponsorships' => 'الكفلاء والكفالات والتبرعات',
        'campaigns' => 'إعداد الحملات الإغاثية وكشوفها',
        'imports' => 'استيراد الكشوف وتدقيق دفعاتها',
    ];

    public function department(): ?Department
    {
        return Department::where('code', 'ORPHANS')->where('is_active', true)->first();
    }

    public function departmentId(): int
    {
        return (int) $this->department()?->id;
    }

    /**
     * The appointed head (head_user_id with the department_head role) or an acting head whose appointment dates
     * include today. Exactly this office assigns field distributors; it never approves exceptions.
     */
    public function isHead(?User $user, ?Department $department = null): bool
    {
        $department ??= $this->department();
        if (! $user || ! $user->is_active || ! $department) {
            return false;
        }
        $today = now(config('orphans.timezone'))->toDateString();
        $roles = DB::table('department_user_roles as a')->join('roles as r', 'r.id', '=', 'a.role_id')
            ->where('a.user_id', $user->id)->where('a.department_id', $department->id)
            ->get(['r.code', 'a.starts_on', 'a.ends_on']);
        $appointed = (int) $department->head_user_id === (int) $user->id && (int) $user->department_id === (int) $department->id
            && $roles->contains(fn ($row): bool => $row->code === 'department_head');
        $acting = $roles->contains(fn ($row): bool => $row->code === 'acting_department_head' && $row->starts_on !== null && $row->ends_on !== null
            && $row->starts_on <= $today && $row->ends_on >= $today);

        return $appointed || $acting;
    }

    /**
     * Everyone who is head of the department today (the appointed head and any acting head whose dates include
     * today): the recipients of head-only alerts. Empty when the office is vacant.
     *
     * @return list<int>
     */
    public function headUserIds(): array
    {
        $department = $this->department();
        if (! $department) {
            return [];
        }
        $candidates = DB::table('department_user_roles as a')->join('roles as r', 'r.id', '=', 'a.role_id')->where('a.department_id', $department->id)
            ->whereIn('r.code', ['department_head', 'acting_department_head'])->pluck('a.user_id')->push($department->head_user_id)->filter()->unique();

        return User::query()->whereIn('id', $candidates)->get()->filter(fn (User $user): bool => $this->isHead($user, $department))
            ->map(fn (User $user): int => (int) $user->id)->values()->all();
    }

    /**
     * Active staff of the department holding a capability through their own grants, plus today's heads. The system
     * administrator is not department staff and is never a recipient by this route.
     *
     * @return list<int>
     */
    public function departmentUsersWith(string $capability): array
    {
        $staff = User::query()->where('is_active', true)->where('department_id', $this->departmentId())->get()
            ->filter(fn (User $user): bool => ! $user->isSystemAdmin() && $this->allows($user, $capability))->map(fn (User $user): int => (int) $user->id);

        return $staff->merge($this->headUserIds())->unique()->values()->all();
    }

    public function isExecutive(?User $user): bool
    {
        return $user !== null && $user->is_active && DB::table('department_user_roles as a')->join('roles as r', 'r.id', '=', 'a.role_id')
            ->where('a.user_id', $user->id)->where('r.code', 'executive_director')->exists();
    }

    public function canManageDelegations(?User $user): bool
    {
        return $user && ($user->isSystemAdmin() || $this->isHead($user));
    }

    public function allows(?User $user, string $capability): bool
    {
        if (! $user || ! $user->is_active || ! array_key_exists($capability, self::CAPABILITIES)) {
            return false;
        }
        // The system admin holds every capability (owner decision, SRS-002 §3); reads are logged and own reviews stay blocked.
        if ($user->isSystemAdmin() || $this->isHead($user)) {
            return true;
        }
        // The executive director reads what a decision needs; editing stays with the department.
        if ($this->isExecutive($user)) {
            return in_array($capability, ['view', 'sensitive'], true);
        }
        $department = $this->department();
        if (! $department || (int) $user->department_id !== (int) $department->id) {
            return false;
        }
        $grants = DB::table('orphan_access_grants')->where('department_id', $department->id)->where('user_id', $user->id)->where('is_active', true)->pluck('capability')->all();

        return in_array('view', $grants, true) && in_array($capability, $grants, true);
    }

    public function assert(?User $user, string $capability): void
    {
        abort_unless($this->allows($user, $capability), 403, 'تحتاج إلى تفويض صريح نافذ لهذه العملية في قسم الكفالات والأيتام.');
    }

    /** Logs a read of a protected record (who, what, when) without any of its content. */
    public function logRead(User $user, string $subjectType, int $subjectId, string $action = 'viewed'): void
    {
        DB::table('orphan_access_log')->insert(['user_id' => $user->id, 'action' => $action, 'subject_type' => $subjectType, 'subject_id' => $subjectId,
            'ip_address' => request()?->ip(), 'created_at' => now()]);
    }
}
