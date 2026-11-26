<?php

declare(strict_types=1);

namespace App\Services\Progress;

use App\Models\GuardianApplication;
use Illuminate\Support\Facades\DB;

/**
 * Stepper for a guardian application from its event log (orphan_registration_events), replacing the
 * steps that used to be written in the browser. A step is "done" only when its event exists:
 * an application rejected before verification shows verification as skipped, not as completed.
 */
final class GuardianApplicationProgress
{
    /**
     * @return array{steps: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public function view(GuardianApplication $application, bool $staff): array
    {
        $events = DB::table('orphan_registration_events as e')->leftJoin('users as u', 'u.id', '=', 'e.staff_user_id')
            ->where('e.guardian_application_id', $application->id)->orderBy('e.id')
            ->get(['e.action', 'e.created_at', 'u.name']);
        $last = fn (string ...$actions): ?object => $events->last(fn (object $row): bool => in_array($row->action, $actions, true));
        $submitted = $last('submitted');
        $verified = $last('verification_completed');
        $decision = $last('approved', 'rejected');
        $returns = $events->where('action', 'needs_changes')->count();
        $status = $application->status;

        $verificationState = match ($status) {
            'awaiting_api', 'verifying' => 'current',
            'verification_failed' => 'rejected',
            'pending_review', 'needs_changes', 'approved' => 'done',
            'rejected' => $verified ? 'done' : 'skipped',
            default => 'upcoming',
        };
        $reviewState = match ($status) {
            'pending_review' => 'current',
            'needs_changes' => 'returned',
            'approved', 'rejected' => $verified || $status === 'approved' ? 'done' : 'skipped',
            default => 'upcoming',
        };
        $decisionState = match ($status) {
            'approved' => 'done',
            'rejected' => 'rejected',
            default => 'upcoming',
        };

        $steps = [
            ['label' => 'تقديم الطلب', 'state' => 'done', 'completedAt' => $submitted?->created_at ?? $application->created_at],
            [
                'label' => 'التحقق من الأهلية (السجل المدني)', 'state' => $verificationState,
                'owner' => $verificationState === 'current' ? 'خدمة التحقق الرسمية' : null,
                'completedAt' => $verified?->created_at,
                'note' => match ($status) {
                    'awaiting_api' => 'خدمة التحقق غير متاحة حاليًا؛ الطلب محفوظ ولن يُعتمد قبل توفرها.',
                    'verification_failed' => 'لم تثبت نتيجة التحقق أهلية مقدم الطلب.',
                    default => null,
                },
            ],
            [
                'label' => $status === 'needs_changes' ? 'استكمال مطلوب من الوصي' : 'مراجعة المشرف', 'state' => $reviewState,
                'owner' => $reviewState === 'current' ? 'قسم الكفالات والأيتام' : ($reviewState === 'returned' ? 'الوصي' : null),
                'attempt' => $returns + 1,
                'note' => $status === 'needs_changes' ? $application->review_note : null,
            ],
            [
                'label' => $status === 'rejected' ? 'رفض الطلب' : 'القرار وربط الأطفال المتحقق منهم', 'state' => $decisionState,
                'owner' => $staff ? $decision?->name : null, 'completedAt' => $decision?->created_at ?? $application->reviewed_at,
                'note' => $status === 'rejected' ? $application->review_note : ($status === 'approved' ? 'رُبط '.$application->imported_children_count.' طفلًا متحققًا منه.' : null),
            ],
        ];
        $steps = array_map(fn (array $step): array => $step + ['owner' => null, 'startedAt' => null, 'completedAt' => null, 'dueAt' => null, 'overdue' => false, 'attempt' => 1, 'note' => null], $steps);

        $summary = match ($status) {
            'awaiting_api', 'verifying' => ['status' => 'بانتظار التحقق', 'tone' => 'warning', 'where' => 'بانتظار خدمة التحقق الرسمية', 'next' => 'مراجعة المشرف بعد التحقق'],
            'verification_failed' => ['status' => 'الأهلية غير مثبتة', 'tone' => 'danger', 'where' => 'نتيجة التحقق لم تثبت الأهلية', 'next' => 'مراجعة القسم'],
            'pending_review' => ['status' => 'بانتظار المراجعة', 'tone' => 'info', 'where' => 'لدى قسم الكفالات والأيتام', 'next' => 'القرار وربط الأطفال المتحقق منهم'],
            'needs_changes' => ['status' => 'مطلوب استكمال', 'tone' => 'warning', 'where' => 'لدى الوصي للاستكمال', 'next' => 'إعادة المراجعة بعد الاستكمال'],
            'approved' => ['status' => 'معتمد', 'tone' => 'success', 'where' => 'اعتُمد الطلب', 'next' => 'لا إجراءات متبقية'],
            'rejected' => ['status' => 'مرفوض', 'tone' => 'danger', 'where' => 'أُغلق الطلب بالرفض', 'next' => 'لا إجراءات متبقية'],
            default => ['status' => $status, 'tone' => 'muted', 'where' => $status, 'next' => '—'],
        };

        return ['steps' => $steps, 'summary' => $summary + ['dueAt' => null, 'overdue' => false, 'partial' => $submitted === null]];
    }
}
