<?php

namespace App\Services\Hr;

use App\Models\User;
use App\Services\Notifications\NotificationCenter;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Staff documents that need HR's attention (HR requirements §4.1, §5 «نقص ملف», §11.1; SRS 3.4.1.1 «قوائم النواقص
 * والتنبيهات»), counted for the dashboards, listed by the staff list filters and announced to the HR head once a
 * day by «hr:document-alerts»:
 *  - expired: a current document whose expiry date has passed;
 *  - expiring: a current document that expires within hr_employee_file_settings.document_expiry_alert_days. The
 *    number of days is the owner's decision: while it is empty only expired documents are flagged;
 *  - missing: an active employee without a current document of a type marked mandatory in «ثوابت النظام»
 *    (employee_document_types, metadata "mandatory"), the same rule as the file's own «الوثائق الناقصة».
 * Only active, undeleted employees count. Notices carry numbers only, never document titles or contents.
 */
final class EmployeeDocumentAlerts
{
    /** To the HR head: documents expired or near expiry, or files missing a mandatory document. */
    public const EVENT = 'employee_document_alert';

    public function __construct(private readonly EmployeeFiles $files, private readonly EmployeeAccess $access, private readonly NotificationCenter $notifications) {}

    /** Days before expiry that a document is flagged; null while the owner has not set it. */
    public function alertDays(): ?int
    {
        if (! Schema::hasColumn('hr_employee_file_settings', 'document_expiry_alert_days')) {
            return null;
        }
        $days = DB::table('hr_employee_file_settings')->where('id', 1)->value('document_expiry_alert_days');

        return $days === null ? null : (int) $days;
    }

    /** Current documents of active employees that expired or expire within the alert window ("d" and "e"). */
    public function expiring(): Builder
    {
        $days = $this->alertDays();
        $until = $days === null ? today()->subDay() : today()->addDays($days);

        return $this->documents()->where('d.expires_on', '<=', $until->toDateString());
    }

    /** Active employees ("e") missing a current document of a mandatory type; null when no type is mandatory. */
    public function missing(): ?Builder
    {
        $types = $this->mandatoryTypeIds();
        if ($types === []) {
            return null;
        }
        $marks = implode(',', array_fill(0, count($types), '?'));

        return $this->employees()->whereRaw("(SELECT count(DISTINCT md.document_type_value_id) FROM employee_documents md WHERE md.employee_id = e.id
            AND md.is_current AND md.archived_at IS NULL AND md.document_type_value_id IN ({$marks})) < ?", [...$types, count($types)]);
    }

    /**
     * @return array{expired: int, expiring: ?int, days: ?int, missing: ?int}
     */
    public function counts(): array
    {
        $days = $this->alertDays();
        $expired = $this->documents()->where('d.expires_on', '<', today()->toDateString())->count();

        return [
            'expired' => $expired,
            'expiring' => $days === null ? null : $this->expiring()->count() - $expired,
            'days' => $days,
            'missing' => $this->missing()?->count(),
        ];
    }

    /**
     * Tells the appointed HR head what needs attention, as two digests. Each digest is sent again only when its
     * set of documents or files changes, so a list that stays the same is not repeated every day.
     *
     * @return array{expired: int, expiring: ?int, missing: ?int, sent: int}
     */
    public function notify(): array
    {
        $counts = $this->counts();
        $headId = DB::table('departments')->where('code', HrServicesDashboard::DEPARTMENT_CODE)->where('is_active', true)->whereNull('deleted_at')->value('head_user_id');
        $head = $headId ? User::query()->find($headId) : null;
        if (! $head || ! $this->access->canVerify($head)) {
            return $counts + ['sent' => 0];
        }
        $sent = 0;

        $documents = $this->expiring()->orderBy('d.id')->get(['d.id', 'd.expires_on']);
        if ($documents->isNotEmpty()) {
            $parts = array_filter([
                $counts['expired'] ? $counts['expired'].' منتهية' : null,
                $counts['expiring'] ? $counts['expiring'].' تنتهي خلال '.$counts['days'].' يومًا' : null,
            ]);
            $sent += (int) $this->notifications->notify((int) $head->id, self::EVENT, [
                'title' => 'وثائق موظفين تحتاج تجديدًا: '.implode('، ', $parts),
                'body' => 'افتح قائمة الموظفين المعنيين وجدّد الوثائق في ملفاتهم.',
                'url' => route('admin.employee-profiles.index', ['state' => 'documents_expiring'], false),
                'dedupe' => 'emp-docs-expiry:'.sha1($documents->map(fn (object $row): string => $row->id.'@'.$row->expires_on.($row->expires_on < today()->toDateString() ? 'x' : ''))->implode(',')).':u'.$head->id,
            ]);
        }

        $missing = $this->missing()?->orderBy('e.id')->pluck('e.id');
        if ($missing !== null && $missing->isNotEmpty()) {
            $sent += (int) $this->notifications->notify((int) $head->id, self::EVENT, [
                'title' => 'ملفات موظفين ينقصها وثائق إلزامية: '.$missing->count(),
                'body' => 'الوثائق الإلزامية كما حُددت في «ثوابت النظام».',
                'url' => route('admin.employee-profiles.index', ['state' => 'documents_missing'], false),
                'dedupe' => 'emp-docs-missing:'.sha1($missing->implode(',').'|'.implode(',', $this->mandatoryTypeIds())).':u'.$head->id,
            ]);
        }

        return $counts + ['sent' => $sent];
    }

    /** Current, unarchived documents with an expiry date, of active employees. */
    private function documents(): Builder
    {
        return DB::table('employee_documents as d')->join('employees as e', 'e.id', '=', 'd.employee_id')
            ->whereNull('e.deleted_at')->where('e.employment_status', 'active')
            ->where('d.is_current', true)->whereNull('d.archived_at')->whereNotNull('d.expires_on');
    }

    private function employees(): Builder
    {
        return DB::table('employees as e')->whereNull('e.deleted_at')->where('e.employment_status', 'active');
    }

    /** @return list<int> */
    private function mandatoryTypeIds(): array
    {
        return collect($this->files->documentTypes(true))->where('mandatory', true)->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
    }
}
