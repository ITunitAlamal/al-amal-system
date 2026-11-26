<?php

namespace App\Services\Workflow;

use App\Services\Attendance\AttendancePermissionWorkflow;
use App\Services\Attendance\ShiftSwapWorkflow;
use App\Services\Correspondence\CorrespondenceEditionWorkflow;
use App\Services\Correspondence\CorrespondenceWorkflow;
use App\Services\Hr\EmployeePayChangeWorkflow;
use App\Services\LeaveWorkflow;
use App\Services\Organization\StructureChangeWorkflow;
use App\Services\Rentals\RentalApplicationWorkflow;
use App\Services\Rentals\RentalContractWorkflow;
use App\Services\Rentals\RentalSettlementWorkflow;
use App\Services\Rentals\RentalTerminationWorkflow;
use Illuminate\Support\Collection;

/**
 * The record types a workflow definition can belong to. "aims_request" is the generic request created from
 * «طلباتي»; the others are module records started from their own screen and handled by their module.
 */
final class WorkflowSubjects
{
    /** @var array<string, string> subject type => Arabic label */
    public const LABELS = [
        WorkflowEngine::SUBJECT_TYPE => 'طلب عام من «طلباتي»',
        LeaveWorkflow::SUBJECT_TYPE => 'طلبات الإجازات',
        'official_correspondence' => 'المراسلات وقرارات المجلس',
        'correspondence_edition' => 'اعتماد النسخ النصية البديلة للمراسلات',
        RentalApplicationWorkflow::SUBJECT_TYPE => 'طلبات الاستئجار',
        RentalContractWorkflow::SUBJECT_TYPE => 'عقود الإيجار (جديد وتجديد وملحق)',
        RentalTerminationWorkflow::SUBJECT_TYPE => 'إنهاء عقود الإيجار والإخلاء',
        RentalSettlementWorkflow::SUBJECT_TYPE => 'تسويات الإيجار',
        EmployeePayChangeWorkflow::SUBJECT_TYPE => 'تعديل رواتب الموظفين وحساباتهم البنكية',
        StructureChangeWorkflow::SUBJECT_TYPE => 'تعديل الهيكل التنظيمي',
        AttendancePermissionWorkflow::SUBJECT_TYPE => 'طلبات الإذن (تأخير، انصراف مبكر، خروج مؤقت)',
        ShiftSwapWorkflow::SUBJECT_TYPE => 'طلبات تبديل المناوبة',
    ];

    /** @var array<string, class-string<WorkflowSubject>> */
    private const HANDLERS = [
        LeaveWorkflow::SUBJECT_TYPE => LeaveWorkflow::class,
        'official_correspondence' => CorrespondenceWorkflow::class,
        'correspondence_edition' => CorrespondenceEditionWorkflow::class,
        RentalApplicationWorkflow::SUBJECT_TYPE => RentalApplicationWorkflow::class,
        RentalContractWorkflow::SUBJECT_TYPE => RentalContractWorkflow::class,
        RentalTerminationWorkflow::SUBJECT_TYPE => RentalTerminationWorkflow::class,
        RentalSettlementWorkflow::SUBJECT_TYPE => RentalSettlementWorkflow::class,
        EmployeePayChangeWorkflow::SUBJECT_TYPE => EmployeePayChangeWorkflow::class,
        StructureChangeWorkflow::SUBJECT_TYPE => StructureChangeWorkflow::class,
        AttendancePermissionWorkflow::SUBJECT_TYPE => AttendancePermissionWorkflow::class,
        ShiftSwapWorkflow::SUBJECT_TYPE => ShiftSwapWorkflow::class,
    ];

    public function handler(string $subjectType): ?WorkflowSubject
    {
        $class = self::HANDLERS[$subjectType] ?? null;

        return $class ? app($class) : null;
    }

    /**
     * Where a request opens: its module screen, or the generic request page.
     */
    public function url(object $instance): string
    {
        $handler = $this->handler((string) $instance->subject_type);

        return $handler
            ? $handler->url((int) $instance->subject_id)
            : route('workflows.show', ['publicId' => $instance->public_id], false);
    }

    /**
     * Titles of module records in a list of instances, one query per module.
     *
     * @param  Collection<int, object>  $instances  rows with subject_type and subject_id
     * @return array<string, array<int, string>> subject type => (subject id => title)
     */
    public function titles(Collection $instances): array
    {
        $titles = [];
        foreach ($instances->groupBy('subject_type') as $type => $rows) {
            $handler = $this->handler((string) $type);
            if ($handler) {
                $titles[$type] = $handler->titles($rows->pluck('subject_id')->map(fn ($id): int => (int) $id)->unique()->values()->all());
            }
        }

        return $titles;
    }
}
