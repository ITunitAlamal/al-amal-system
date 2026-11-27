<?php

namespace App\Services\Orphans;

use App\Models\Guardian;
use App\Models\Orphan;
use App\Models\OrphanGuardian;
use App\Models\OrphanGuardianship;
use App\Models\Person;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Notifications\NotificationCenter;
use App\Services\Portal\PortalNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Guardian portal applications. The account decision and every child's guardianship decision are separate: approving
 * the account opens no child file; a child becomes visible only through its own approved, current guardianship.
 */
final class PortalApplications
{
    public const STATUS = ['submitted' => 'جديد', 'under_review' => 'قيد المراجعة', 'needs_completion' => 'بانتظار الاستكمال', 'resubmitted' => 'أُعيد تقديمه',
        'completed' => 'منتهٍ', 'rejected' => 'مرفوض', 'withdrawn' => 'مسحوب'];

    public const CHILD_STATUS = ['pending' => 'بانتظار القرار', 'approved' => 'وصاية معتمدة', 'rejected' => 'مرفوض', 'needs_completion' => 'بانتظار استكمال'];

    public function __construct(private readonly Registry $registry, private readonly Persons $persons, private readonly OrphanAccess $access,
        private readonly AuditService $audit, private readonly NotificationCenter $notifications) {}

    // Requests are created, saved and submitted by Registration\FamilyRegistrations (portal and staff).

    public function assign(object $application, ?int $userId, User $actor): void
    {
        $self = $userId !== null && (int) $userId === (int) $actor->id && $application->assigned_to === null;
        abort_unless($this->access->canAssign($actor) || ($self && $this->access->allows($actor, 'portal')), 403, 'الإسناد لرئيس القسم؛ ويمكن للموظف المخول استلام طلب غير مسند.');
        $assignee = $userId === null ? null : ((int) $userId === (int) $actor->id ? $actor : User::find($userId));
        if ($assignee && ($conflict = $this->reviewConflict($application, $assignee)) !== null) {
            abort(422, 'لا يُسند الطلب إلى هذا الموظف: '.$conflict);
        }
        DB::table('portal_applications')->where('id', $application->id)->update(['assigned_to' => $userId, 'assigned_at' => now(),
            'status' => $application->status === 'submitted' ? 'under_review' : $application->status, 'updated_at' => now()]);
        $this->event($application->id, 'assigned', $actor->id, payload: ['assigned_to' => $userId]);
        if ($userId && $userId !== $actor->id) {
            $this->notifications->notify($userId, 'orphans_portal_update', ['title' => 'أُسند إليك طلب بوابة '.$application->reference_no, 'url' => '/orphans/reviews/'.$application->public_id,
                'dedupe' => 'portal-assign-'.$application->id.'-'.$userId]);
        }
    }

    public function saveChecklist(object $application, array $checklist, User $actor): void
    {
        $this->assertReviewer($application, $actor);
        DB::table('portal_applications')->where('id', $application->id)->update(['checklist' => json_encode($checklist), 'updated_at' => now()]);
        $this->event($application->id, 'checklist_saved', $actor->id, payload: $checklist);
    }

    /** Ensures the applicant exists in the guardian registry (reusing a person with the same identity). */
    public function ensureGuardian(object $application, User $actor): OrphanGuardian
    {
        if ($application->matched_guardian_id) {
            return OrphanGuardian::findOrFail($application->matched_guardian_id);
        }
        $existing = $application->id_hash ? Person::query()->where('id_hash', $application->id_hash)->whereIn('id_format', ['valid_format', 'other_type'])->first() : null;
        $guardian = $this->registry->createGuardian($existing ? [] : [
            ...collect((array) $application)->only(['first_name', 'father_name', 'grandfather_name', 'family_name'])->all(),
            'full_name' => $application->full_name, 'id_number' => $application->id_number ? decrypt($application->id_number) : null, 'phone' => $application->phone,
        ], $existing?->id, [], $actor);
        DB::table('portal_applications')->where('id', $application->id)->update(['matched_guardian_id' => $guardian->id, 'updated_at' => now()]);

        return $guardian;
    }

    public function decideAccount(object $application, string $decision, ?string $note, User $actor): void
    {
        $this->assertReviewer($application, $actor);
        abort_unless($application->source === 'portal' && $application->guardian_account_id, 409, 'هذا الطلب أُدخل من القسم؛ لا يوجد حساب بوابة للبت فيه.');
        if ($decision === 'reject' && blank($note)) {
            throw ValidationException::withMessages(['note' => 'سبب رفض الحساب مطلوب.']);
        }
        DB::transaction(function () use ($application, $decision, $note, $actor): void {
            $account = Guardian::query()->lockForUpdate()->findOrFail($application->guardian_account_id);
            if ($decision === 'approve') {
                $guardian = $this->ensureGuardian($application, $actor);
                abort_if($guardian->portal_account_id && (int) $guardian->portal_account_id !== (int) $account->id, 409, 'هذا الوصي مرتبط بحساب بوابة آخر.');
                $guardian->forceFill(['portal_account_id' => $account->id, 'updated_by' => $actor->id])->save();
                $account->forceFill(['person_id' => $guardian->person_id, 'account_status' => 'approved', 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $note])->save();
            } else {
                $account->forceFill(['account_status' => 'rejected', 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $note])->save();
            }
            $this->event($application->id, 'account_'.($decision === 'approve' ? 'approved' : 'rejected'), $actor->id, $note);
            $this->audit->recordRow('orphans.portal.account_'.$decision, 'guardians', $account->id, null, ['application' => $application->reference_no], $actor->id, request()?->ip());
            $this->settle($application->id);
        });
    }

    /**
     * Approves one child's guardianship: links an existing orphan file, or creates the file from the claim — in both
     * cases with an approved relationship to the applicant's guardian record, dated and evidenced.
     */
    public function approveChild(object $child, array $data, User $actor): void
    {
        $this->access->assertDecision($actor, 'portal');
        $application = DB::table('portal_applications')->find($child->application_id);
        $this->assertReviewer($application, $actor);
        abort_unless(in_array($child->status, ['pending', 'needs_completion'], true), 409, 'تم البت في هذا الطفل مسبقًا.');
        if (empty($data['starts_on'])) {
            throw ValidationException::withMessages(['starts_on' => 'حدد تاريخ بدء سريان الوصاية.']);
        }
        DB::transaction(function () use ($child, $application, $data, $actor): void {
            if (filled($application->form_data)) {
                // Family request: the child's own guardian, shared parents and household come from the request snapshot.
                [$orphan, $guardianship] = app(Registration\FamilyPromotion::class)->promote($application, $child, $data, $actor);
                DB::table('portal_application_children')->where('id', $child->id)->update(['status' => 'approved', 'matched_orphan_id' => $orphan->id, 'guardianship_id' => $guardianship->id,
                    'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $data['note'] ?? null, 'updated_at' => now()]);
                $this->event($application->id, 'child_approved', $actor->id, $data['note'] ?? null, $child->id);
                $this->settle($application->id);

                return;
            }
            $guardian = $this->ensureGuardian($application, $actor);
            $link = ['link_status' => 'approved', 'kinship_value_id' => $child->kinship_value_id, 'authority_kind_value_id' => $data['authority_kind_value_id'] ?? null,
                'legal_basis' => $data['legal_basis'] ?? null, 'starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on'] ?? null, 'source' => 'portal',
                'evidence_note' => $data['evidence_note'] ?? 'طلب بوابة '.$application->reference_no, 'decision_note' => $data['note'] ?? null];
            $orphanId = $data['orphan_id'] ?? $child->matched_orphan_id;
            if ($orphanId) {
                $orphan = Orphan::findOrFail($orphanId);
                $existing = OrphanGuardianship::query()->where('orphan_id', $orphan->id)->where('guardian_id', $guardian->id)->whereIn('status', OrphanGuardianship::LIVE)->first();
                if ($existing && $existing->status !== 'approved') {
                    $this->registry->decideGuardianship($existing, 'approve', $link, $actor);
                    $guardianship = $existing->fresh();
                } else {
                    $guardianship = $existing ?? $this->registry->addGuardianship($orphan, $guardian, $link, $actor);
                }
            } else {
                $orphan = $this->registry->createOrphan([
                    ...collect((array) $child)->only(['first_name', 'father_name', 'grandfather_name', 'family_name', 'sex'])->all(), 'full_name' => $child->full_name,
                    'id_number' => $child->id_number ? decrypt($child->id_number) : null, 'date_of_birth' => $child->date_of_birth,
                ], ['registration_source' => 'portal'], ['guardian_id' => $guardian->id, ...$link], [], $actor);
                $guardianship = OrphanGuardianship::query()->where('orphan_id', $orphan->id)->where('guardian_id', $guardian->id)->firstOrFail();
            }
            $this->registry->refreshCompleteness($orphan);
            DB::table('portal_application_children')->where('id', $child->id)->update(['status' => 'approved', 'matched_orphan_id' => $orphan->id, 'guardianship_id' => $guardianship->id,
                'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $data['note'] ?? null, 'updated_at' => now()]);
            $this->event($application->id, 'child_approved', $actor->id, $data['note'] ?? null, $child->id);
            $this->settle($application->id);
        });
    }

    public function rejectChild(object $child, string $note, User $actor): void
    {
        if (blank($note)) {
            throw ValidationException::withMessages(['note' => 'سبب الرفض مطلوب ويظهر للوصي.']);
        }
        $application = DB::table('portal_applications')->find($child->application_id);
        $this->assertReviewer($application, $actor);
        abort_unless(in_array($child->status, ['pending', 'needs_completion'], true), 409, 'تم البت في هذا الطفل مسبقًا.');
        DB::transaction(function () use ($child, $application, $note, $actor): void {
            DB::table('portal_application_children')->where('id', $child->id)->update(['status' => 'rejected', 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $note, 'updated_at' => now()]);
            $this->event($application->id, 'child_rejected', $actor->id, $note, $child->id);
            $this->settle($application->id);
        });
    }

    /** One editable message to the applicant; approved decisions stay as they are. */
    public function requestCompletion(object $application, string $message, array $childIds, User $actor): void
    {
        $this->assertReviewer($application, $actor);
        DB::transaction(function () use ($application, $message, $childIds, $actor): void {
            DB::table('portal_applications')->where('id', $application->id)->update(['status' => 'needs_completion', 'completion_request' => $message, 'completion_requested_at' => now(), 'updated_at' => now()]);
            DB::table('portal_application_children')->where('application_id', $application->id)->whereIn('id', $childIds)->where('status', 'pending')->update(['status' => 'needs_completion', 'updated_at' => now()]);
            $this->event($application->id, 'completion_requested', $actor->id, $message, payload: ['children' => $childIds]);
        });
    }

    public function rejectApplication(object $application, string $note, User $actor): void
    {
        $this->assertReviewer($application, $actor);
        DB::transaction(function () use ($application, $note, $actor): void {
            DB::table('portal_application_children')->where('application_id', $application->id)->whereIn('status', ['pending', 'needs_completion'])
                ->update(['status' => 'rejected', 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $note, 'updated_at' => now()]);
            DB::table('portal_applications')->where('id', $application->id)->update(['status' => 'rejected', 'decision_note' => $note, 'decided_by' => $actor->id, 'decided_at' => now(), 'updated_at' => now()]);
            $this->event($application->id, 'rejected', $actor->id, $note);
        });
    }

    /** Children a portal account may see: account approved and active, guardianship approved and in force today. */
    public function visibleChildren(Guardian $account): array
    {
        if (! $account->is_active || $account->account_status !== 'approved' || ! $account->person_id) {
            return [];
        }
        $today = today()->toDateString();

        return DB::table('orphan_guardians as gg')->join('orphan_guardianships as g', 'g.guardian_id', '=', 'gg.id')->join('orphans as o', 'o.id', '=', 'g.orphan_id')
            ->join('persons as p', 'p.id', '=', 'o.person_id')->where('gg.portal_account_id', $account->id)->where('gg.person_id', $account->person_id)
            ->where('g.status', 'approved')->where(fn ($q) => $q->whereNull('g.starts_on')->orWhere('g.starts_on', '<=', $today))
            ->where(fn ($q) => $q->whereNull('g.ends_on')->orWhere('g.ends_on', '>=', $today))->whereNotIn('o.status', ['ended', 'archived', 'provisional'])
            ->orderBy('p.full_name')->get(['o.reference_no', 'p.full_name', 'p.date_of_birth', 'g.starts_on', 'g.ends_on'])->map(fn ($r) => (array) $r)->all();
    }

    private function assertReviewer(object $application, User $actor): void
    {
        abort_unless($this->access->allows($actor, 'portal'), 403, 'مراجعة طلبات البوابة تحتاج قدرة «مراجعة طلبات البوابة».');
        abort_if(in_array($application->status, ['draft', 'completed', 'rejected', 'withdrawn'], true), 409, $application->status === 'draft' ? 'الطلب مسودة لم تُرسل بعد.' : 'الطلب مغلق.');
        abort_if($application->assigned_to && (int) $application->assigned_to !== (int) $actor->id && ! $this->access->canAssign($actor), 403, 'الطلب مسند لموظف آخر.');
        $conflict = $this->reviewConflict($application, $actor);
        abort_if($conflict !== null, 403, (string) $conflict);
    }

    /**
     * Why this person may not review or approve the request (owner decision 4, 2026-10-22: nobody reviews their own
     * request): they entered it from the department, or they are the applicant or the matched guardian themselves
     * (by the identity number on their employee file). Null when none applies; the head is bound by it too.
     */
    public function reviewConflict(object $application, User $actor): ?string
    {
        if ($application->source === 'staff' && (int) $application->created_by_user === (int) $actor->id) {
            return 'أدخلتَ هذا الطلب بنفسك من القسم؛ يراجعه ويبت فيه موظف آخر مخوّل أو رئيس القسم.';
        }
        $own = DB::table('employees')->where('user_id', $actor->id)->whereNull('deleted_at')->whereNotNull('national_id')->pluck('national_id')
            ->map(fn ($n): string => Identity::clean((string) $n))->filter()->map(fn (string $n): string => Identity::hash($n))->all();
        if ($own === []) {
            return null;
        }
        $guardianHash = $application->matched_guardian_id ? DB::table('orphan_guardians as g')->join('persons as p', 'p.id', '=', 'g.person_id')
            ->where('g.id', $application->matched_guardian_id)->value('p.id_hash') : null;

        return array_intersect($own, array_filter([$application->id_hash, $guardianHash])) !== [] ? 'أنت مقدم هذا الطلب أو الوصي فيه؛ لا تراجع طلبك ولا تبت فيه.' : null;
    }

    /** Closes the application when the account and every child have a decision. */
    private function settle(int $applicationId): void
    {
        $application = DB::table('portal_applications')->find($applicationId);
        // A staff-entered request has no portal account to decide.
        $account = $application->guardian_account_id ? DB::table('guardians')->where('id', $application->guardian_account_id)->value('account_status') : 'approved';
        $open = DB::table('portal_application_children')->where('application_id', $applicationId)->whereIn('status', ['pending', 'needs_completion'])->exists();
        if (! $open && in_array($account, ['approved', 'rejected'], true)) {
            DB::table('portal_applications')->where('id', $applicationId)->update(['status' => 'completed', 'decided_at' => now(), 'updated_at' => now()]);
            $this->event($applicationId, 'completed');
        } elseif ($application->status === 'submitted') {
            DB::table('portal_applications')->where('id', $applicationId)->update(['status' => 'under_review', 'updated_at' => now()]);
        }
    }

    public function event(int $applicationId, string $action, ?int $user = null, ?string $note = null, ?int $childId = null, ?array $payload = null, ?int $guardian = null): void
    {
        $eventId = DB::table('portal_application_events')->insertGetId(['application_id' => $applicationId, 'child_id' => $childId, 'action' => $action, 'actor_user_id' => $user,
            'actor_guardian_id' => $guardian, 'note' => $note, 'payload' => $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null, 'created_at' => now()]);
        // The applicant's portal notice: a reference number only, never a child's name or a reviewer's note.
        $notice = [
            'submitted' => ['info', 'استلمنا طلبك %s وسيُراجع قريبًا'], 'resubmitted' => ['info', 'استلمنا استكمال الطلب %s'],
            'completion_requested' => ['action', 'مطلوب استكمال بيانات الطلب %s'], 'account_approved' => ['decision', 'اعتُمد حسابك في البوابة'],
            'account_rejected' => ['decision', 'صدر قرار بشأن حسابك في البوابة'], 'child_approved' => ['decision', 'صدر قرار بشأن أحد الأطفال في الطلب %s'],
            'child_rejected' => ['decision', 'صدر قرار بشأن أحد الأطفال في الطلب %s'], 'completed' => ['decision', 'اكتمل البت في الطلب %s'], 'rejected' => ['decision', 'صدر قرار في الطلب %s'],
        ][$action] ?? null;
        if ($notice) {
            $application = DB::table('portal_applications')->where('id', $applicationId)->first(['public_id', 'reference_no', 'guardian_account_id', 'source']);
            if ($application?->source === 'portal') {
                app(PortalNotifier::class)->notify((int) $application->guardian_account_id, $notice[0], sprintf($notice[1], $application->reference_no),
                    '/guardian/requests/'.$application->public_id, null, 'app-event-'.$eventId);
            }
        }
    }
}
