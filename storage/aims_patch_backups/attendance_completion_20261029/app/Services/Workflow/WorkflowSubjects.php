<?php

namespace App\Services\Workflow;

use App\Services\Correspondence\CorrespondenceEditionWorkflow;
use App\Services\Correspondence\CorrespondenceWorkflow;
use App\Services\Hr\EmployeePayChangeWorkflow;
use App\Services\LeaveWorkflow;
use App\Services\Organization\StructureChangeWorkflow;
use App\Services\Orphans\OrphanApplicationWorkflow;
use App\Services\Orphans\OrphanAssistanceWorkflow;
use App\Services\Orphans\OrphanChangeWorkflow;
use App\Services\Orphans\OrphanVisitWorkflow;
use App\Services\Orphans\ScoringRulesetWorkflow;
use App\Services\Orphans\SponsorshipWorkflow;
use App\Services\Relief\ReliefCampaignWorkflow;
use App\Services\Relief\ReliefClosureWorkflow;
use App\Services\Relief\ReliefExceptionWorkflow;
use App\Services\Relief\ReliefRosterWorkflow;
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
        OrphanApplicationWorkflow::SUBJECT_TYPE => 'طلبات اعتماد ملفات الأيتام',
        OrphanChangeWorkflow::SUBJECT_TYPE => 'تعديل بيانات ملفات الأيتام',
        OrphanVisitWorkflow::SUBJECT_TYPE => 'تقارير الزيارات الاجتماعية',
        ScoringRulesetWorkflow::SUBJECT_TYPE => 'قواعد التقييم والأولوية',
        ReliefCampaignWorkflow::SUBJECT_TYPE => 'اعتماد الحملات الإغاثية',
        ReliefRosterWorkflow::SUBJECT_TYPE => 'كشوف مستفيدي الحملات',
        ReliefExceptionWorkflow::SUBJECT_TYPE => 'استثناءات الحملات (المدير التنفيذي حصريًا)',
        ReliefClosureWorkflow::SUBJECT_TYPE => 'إغلاق الحملات الإغاثية',
        SponsorshipWorkflow::SUBJECT_TYPE => 'اعتماد الكفالات وتعديلاتها',
        OrphanAssistanceWorkflow::SUBJECT_TYPE => 'مساعدات الأسر والأيتام',
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
        OrphanApplicationWorkflow::SUBJECT_TYPE => OrphanApplicationWorkflow::class,
        OrphanChangeWorkflow::SUBJECT_TYPE => OrphanChangeWorkflow::class,
        OrphanVisitWorkflow::SUBJECT_TYPE => OrphanVisitWorkflow::class,
        ScoringRulesetWorkflow::SUBJECT_TYPE => ScoringRulesetWorkflow::class,
        ReliefCampaignWorkflow::SUBJECT_TYPE => ReliefCampaignWorkflow::class,
        ReliefRosterWorkflow::SUBJECT_TYPE => ReliefRosterWorkflow::class,
        ReliefExceptionWorkflow::SUBJECT_TYPE => ReliefExceptionWorkflow::class,
        ReliefClosureWorkflow::SUBJECT_TYPE => ReliefClosureWorkflow::class,
        SponsorshipWorkflow::SUBJECT_TYPE => SponsorshipWorkflow::class,
        OrphanAssistanceWorkflow::SUBJECT_TYPE => OrphanAssistanceWorkflow::class,
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
