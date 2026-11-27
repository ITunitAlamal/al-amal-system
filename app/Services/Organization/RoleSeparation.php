<?php

declare(strict_types=1);

namespace App\Services\Organization;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Roles one person never holds in overlapping appointment periods (owner decisions 5 and 6, 2026-11-27):
 * - the board secretary («أمين سر مجلس الإدارة», board_secretary) and the Secretariat duty («السكرتاريا», the
 *   secretariat role that registers and files Board decisions — CorrespondenceAccess::role / CorrespondenceWorkflow);
 * - the Board treasurer (finance_board_treasurer, a Board member) and the technical system_admin role.
 * Every assignment screen checks here before writing; the trigger installed by
 * 2026_11_30_000034_role_separation_guards applies the same pairs to department_user_roles as a backstop.
 * A board secretary / executive director pair is not a rule here: the owner has not decided it (only the older
 * refusal in EmployeeController::store remains).
 */
final class RoleSeparation
{
    /** @var list<array{0: string, 1: string, 2: string}> role, role, why they stay apart */
    public const RULES = [
        ['board_secretary', 'secretariat', 'لا يجمع موظف واحد صفة أمين سر مجلس الإدارة ودور «السكرتاريا»: المهمتان منفصلتان بقرار الإدارة، فيُسند كل منهما إلى موظف مختلف.'],
        ['finance_board_treasurer', 'system_admin', 'دور «أمين صندوق مجلس الإدارة» يحمله عضو في مجلس الإدارة، ولا يجتمع مع دور «مدير النظام» التقني في حساب واحد.'],
    ];

    /**
     * Why the user may not hold this role between these dates, or null when nothing conflicts. A missing start
     * means from today (a new designation does not reach back over an appointment that already ended); a missing
     * end means open. $ignore leaves out the assignment being changed.
     */
    public static function conflict(int $userId, string $roleCode, ?string $startsOn = null, ?string $endsOn = null, ?int $ignore = null): ?string
    {
        $startsOn ??= today()->toDateString();
        if ($endsOn !== null && $startsOn > $endsOn) {
            return null;
        }
        $others = [];
        foreach (self::RULES as [$first, $second, $message]) {
            if ($roleCode === $first) {
                $others[$second] = $message;
            } elseif ($roleCode === $second) {
                $others[$first] = $message;
            }
        }
        if ($others === []) {
            return null;
        }
        $held = DB::table('department_user_roles as a')->join('roles as r', 'r.id', '=', 'a.role_id')
            ->where('a.user_id', $userId)->whereIn('r.code', array_keys($others))
            ->when($ignore !== null, fn ($q) => $q->where('a.id', '<>', $ignore))
            ->when($endsOn !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('a.starts_on')->orWhere('a.starts_on', '<=', $endsOn)))
            ->where(fn ($w) => $w->whereNull('a.ends_on')->orWhere('a.ends_on', '>=', $startsOn))
            ->value('r.code');

        return $held !== null ? $others[$held] : null;
    }

    /** Refuses the assignment with the rule's Arabic reason, under the given form field. */
    public static function assertAllowed(int $userId, string $roleCode, string $field = 'role_id', ?string $startsOn = null, ?string $endsOn = null, ?int $ignore = null): void
    {
        $message = self::conflict($userId, $roleCode, $startsOn, $endsOn, $ignore);
        if ($message !== null) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
