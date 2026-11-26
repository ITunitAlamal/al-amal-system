<?php

namespace App\Services\Rentals;

use App\Models\User;
use App\Services\Workflow\WorkflowSubject;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Rental application on the central engine (approved document §4): the file officer's study goes to the executive
 * director, who accepts (the proposed unit is reserved), asks for more, waitlists or rejects with a reason.
 */
final class RentalApplicationWorkflow implements WorkflowSubject
{
    public const SUBJECT_TYPE = 'rental_application';

    public const DEFINITION = 'RENTAL_APPLICATION';

    public const LABELS = ['draft' => 'مسودة', 'in_review' => 'بانتظار قرار المدير التنفيذي', 'returned' => 'مُعاد للاستكمال', 'waitlisted' => 'قائمة الانتظار',
        'approved' => 'مقبول (العين محجوزة)', 'rejected' => 'مرفوض', 'cancelled' => 'مغلق دون تعاقد', 'contracted' => 'تم التعاقد'];

    private const STATUS_BY_STEP = ['officer' => 'returned', 'ed_decision' => 'in_review', 'waitlist' => 'waitlisted', 'approved' => 'approved', 'rejected' => 'rejected', 'withdrawn' => 'cancelled'];

    public function __construct(private readonly RentalUnits $units, private readonly RentalsAccess $access, private readonly RentalAudit $audit) {}

    public function beforeMove(object $instance, object $from, object $target, int $actorId): void
    {
        $application = DB::table('aims_rental_applications')->where('id', $instance->subject_id)->lockForUpdate()->first();
        abort_unless($application, 404);
        if (in_array($from->code, ['officer', 'waitlist'], true)) {
            abort_unless($this->access->canManage(User::findOrFail($actorId)), 403, 'إعداد طلبات الاستئجار لمسؤول ملف الإيجارات.');
        }
        if ($target->code === 'approved' && $application->unit_id) {
            $unit = DB::table('aims_property_units')->where('id', $application->unit_id)->lockForUpdate()->first();
            if (! $unit || ! $unit->rentable || $unit->availability !== 'vacant') {
                throw ValidationException::withMessages(['workflow' => 'العين المقترحة لم تعد شاغرة؛ أعد الطلب لمسؤول الملف لاختيار عين أخرى، أو ضعه في قائمة الانتظار.']);
            }
        }
    }

    public function afterMove(object $instance, object $from, object $target, object $transition, int $actorId, ?string $comment): void
    {
        $status = self::STATUS_BY_STEP[$target->code] ?? null;
        if ($status === null) {
            return;
        }
        $application = DB::table('aims_rental_applications')->where('id', $instance->subject_id)->first();
        $update = ['status' => $status, 'updated_at' => now()];
        if (in_array($target->code, ['approved', 'rejected', 'waitlist', 'withdrawn'], true)) {
            $update += ['decided_by' => $actorId, 'decided_at' => now(), 'decision_reason' => $comment];
        }
        if ($target->code === 'approved' && $application->unit_id) {
            $this->units->change((int) $application->unit_id, 'reserved', 'تخصيص مبدئي لطلب الاستئجار '.$instance->reference_no, 'application', $actorId, 'application', (int) $application->id);
            $update['reserved_at'] = now();
        }
        DB::table('aims_rental_applications')->where('id', $application->id)->update($update);
        $this->audit->log($actorId, 'rental.application.'.$transition->action_code, 'aims_rental_applications', (int) $application->id,
            ['status' => $application->status], ['status' => $status, 'comment' => $comment, 'workflow' => $instance->reference_no]);
    }

    public function conditionContext(object $instance): array
    {
        return [];
    }

    public function url(int $subjectId): string
    {
        return '/admin/rentals/records/application/'.$subjectId;
    }

    public function titles(array $subjectIds): array
    {
        return DB::table('aims_rental_applications as a')->join('aims_rental_tenants as t', 't.id', '=', 'a.tenant_id')->whereIn('a.id', $subjectIds)
            ->pluck('t.name', 'a.id')->map(fn (string $name): string => 'طلب استئجار: '.$name)->all();
    }
}
