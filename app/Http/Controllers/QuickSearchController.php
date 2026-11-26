<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Configuration\NavigationBuilder;
use App\Services\Workflow\WorkflowInbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Top-bar search. Each group is built from what the user may already open:
 * their menu pages, their own requests and tasks, their leave requests, and — only for those who
 * manage them — employee files and departments. Nothing outside the user's scope is queried.
 */
class QuickSearchController extends Controller
{
    private const LIMIT = 5;

    public function __invoke(Request $request, NavigationBuilder $navigation, WorkflowInbox $inbox): JsonResponse
    {
        $term = trim((string) $request->validate(['q' => ['required', 'string', 'min:2', 'max:60']])['q']);
        $user = $request->user();
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
        $needle = $this->normalize($term);

        $groups = [
            ['label' => 'الصفحات', 'items' => $this->pages($navigation->forUser($user), $needle)],
            ['label' => 'طلباتي', 'items' => $this->myRequests($user, $like)],
            ['label' => 'مهامي', 'items' => $this->myTasks($inbox, $user, $needle)],
            ['label' => 'إجازاتي', 'items' => $this->myLeaves($user, $like, $term)],
        ];
        if ($user->canManageEmployees()) {
            $groups[] = ['label' => 'ملفات الموظفين', 'items' => $this->employees($like)];
        }
        if ($user->canManageOrganization()) {
            $groups[] = ['label' => 'الأقسام', 'items' => $this->departments($like)];
        }

        return response()->json(['groups' => array_values(array_filter($groups, fn (array $group): bool => $group['items'] !== []))]);
    }

    /**
     * @param  list<array<string, mixed>>  $menu
     * @return list<array{title: string, subtitle: ?string, url: string}>
     */
    private function pages(array $menu, string $needle): array
    {
        $found = [];
        $walk = function (array $items, ?string $parent) use (&$walk, &$found, $needle): void {
            foreach ($items as $item) {
                if (! empty($item['url']) && str_contains($this->normalize((string) $item['label']), $needle)) {
                    $found[$item['url']] = ['title' => $item['label'], 'subtitle' => $parent, 'url' => $item['url']];
                }
                $walk($item['children'] ?? [], $item['label']);
            }
        };
        $walk($menu, null);

        return array_slice(array_values($found), 0, self::LIMIT);
    }

    /**
     * @return list<array{title: string, subtitle: ?string, url: string}>
     */
    private function myRequests(User $user, string $like): array
    {
        return DB::table('workflow_instances as i')
            ->join('workflow_versions as v', 'v.id', '=', 'i.workflow_version_id')
            ->join('workflow_definitions as d', 'd.id', '=', 'v.workflow_definition_id')
            ->leftJoin('aims_requests as r', fn ($join) => $join->on('r.id', '=', 'i.subject_id')->where('i.subject_type', 'aims_request'))
            ->where('i.requested_by', $user->id)
            ->where(fn ($query) => $query->where('i.reference_no', 'ilike', $like)->orWhere('r.title', 'ilike', $like)->orWhere('d.name', 'ilike', $like))
            ->orderByDesc('i.created_at')->limit(self::LIMIT)
            ->get(['i.public_id', 'i.reference_no', 'r.title', 'd.name'])
            ->map(fn (object $row): array => [
                'title' => $row->title ?: $row->name,
                'subtitle' => $row->reference_no,
                'url' => route('workflows.show', ['publicId' => $row->public_id], false),
            ])->all();
    }

    /**
     * @return list<array{title: string, subtitle: ?string, url: string}>
     */
    private function myTasks(WorkflowInbox $inbox, User $user, string $needle): array
    {
        return $inbox->tasksFor($user)
            ->filter(fn (object $task): bool => str_contains($this->normalize(implode(' ', [$task->title, $task->reference_no, $task->workflow_name, $task->requester_name])), $needle))
            ->take(self::LIMIT)
            ->map(fn (object $task): array => [
                'title' => $task->title ?: $task->workflow_name,
                'subtitle' => trim(($task->reference_no ?? '').' · '.($task->step_name ?? ''), ' ·'),
                'url' => route('workflows.show', ['publicId' => $task->public_id], false),
            ])->values()->all();
    }

    /**
     * @return list<array{title: string, subtitle: ?string, url: string}>
     */
    private function myLeaves(User $user, string $like, string $term): array
    {
        return DB::table('employee_leave_requests as l')
            ->leftJoin('master_data_values as t', 't.id', '=', 'l.leave_type_value_id')
            ->where('l.requested_by', $user->id)
            ->where(fn ($query) => $query->where('t.name_ar', 'ilike', $like)
                ->when(ctype_digit($term), fn ($inner) => $inner->orWhere('l.id', (int) $term)))
            ->orderByDesc('l.id')->limit(self::LIMIT)
            ->get(['l.id', 'l.start_date', 'l.end_date', 't.name_ar'])
            ->map(fn (object $row): array => [
                'title' => ($row->name_ar ?? 'إجازة').' #'.$row->id,
                'subtitle' => $row->start_date.' ← '.$row->end_date,
                'url' => route('leaves.show', ['leave' => $row->id], false),
            ])->all();
    }

    /**
     * @return list<array{title: string, subtitle: ?string, url: string}>
     */
    private function employees(string $like): array
    {
        return DB::table('employees')
            ->whereNull('deleted_at')
            ->where(fn ($query) => $query->where('employee_number', 'ilike', $like)
                ->orWhere('first_name_ar', 'ilike', $like)->orWhere('family_name_ar', 'ilike', $like))
            ->orderBy('employee_number')->limit(self::LIMIT)
            ->get(['id', 'employee_number', 'first_name_ar', 'family_name_ar'])
            ->map(fn (object $row): array => [
                'title' => trim($row->first_name_ar.' '.$row->family_name_ar),
                'subtitle' => $row->employee_number,
                'url' => route('admin.employee-profiles.show', ['employee' => $row->id], false),
            ])->all();
    }

    /**
     * @return list<array{title: string, subtitle: ?string, url: string}>
     */
    private function departments(string $like): array
    {
        return DB::table('departments')->whereNull('deleted_at')
            ->where(fn ($query) => $query->where('name', 'ilike', $like)->orWhere('code', 'ilike', $like))
            ->orderBy('name')->limit(self::LIMIT)
            ->get(['code', 'name', 'is_active'])
            ->map(fn (object $row): array => [
                'title' => $row->name,
                'subtitle' => $row->is_active ? $row->code : $row->code.' · غير نشط',
                'url' => route('admin.departments.vue', [], false),
            ])->all();
    }

    /** Lower-case and fold Arabic letter variants so «اجازة» finds «إجازة». */
    private function normalize(string $text): string
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[\x{064B}-\x{0652}\x{0640}]/u', '', $text) ?? $text;

        return strtr($text, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ة' => 'ه', 'ى' => 'ي']);
    }
}
