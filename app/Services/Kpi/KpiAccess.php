<?php

namespace App\Services\Kpi;

use App\Services\Kpi\Sources\KpiSource;
use Illuminate\Support\Facades\DB;

/**
 * Which definitions a viewer sees, and which records each figure may aggregate.
 *
 * - executive: institution-wide viewers only.
 * - owner: institution-wide viewers and those answering for the owner department or a listed viewer department;
 *   they see the whole domain (e.g. Accounts sees every rent due).
 * - shared: as owner, plus every other KPI viewer for the records of their own departments.
 * A role list only narrows visibility (the system administrator is never narrowed). Placement on a home page
 * never widens anything.
 */
final class KpiAccess
{
    public function visible(object $definition, KpiScope $scope, ?KpiSource $source): bool
    {
        $roles = $this->list($definition->visible_role_codes ?? '[]');
        if ($roles !== [] && ! $scope->administrator && array_intersect($roles, $scope->roles) === []) {
            return false;
        }
        if ($scope->institutional) {
            return true;
        }

        return match ($definition->audience) {
            'owner' => $this->ownerView($definition, $scope),
            'shared' => $this->ownerView($definition, $scope) || $source === null || $source->sliceable(),
            default => false,
        };
    }

    public function ownerView(object $definition, KpiScope $scope): bool
    {
        $owners = array_filter([$definition->owner_department_id, ...$this->list($definition->viewer_department_ids ?? '[]')]);
        foreach ($owners as $departmentId) {
            if ($scope->covers((int) $departmentId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The records the viewer may aggregate, or the reason no figure can be shown to them.
     */
    public function dataScope(object $definition, KpiScope $scope, KpiSource $source, ?int $departmentId): KpiDataScope|string
    {
        $wide = $scope->institutional || $this->ownerView($definition, $scope);
        if ($departmentId !== null) {
            if (! $source->sliceable()) {
                return 'هذا المؤشر لا يُنسب إلى الأقسام؛ اختر «كل نطاقي» لعرضه.';
            }
            $departments = $scope->subtree($departmentId);

            return new KpiDataScope($departments, (string) DB::table('departments')->where('id', $departmentId)->value('name'));
        }
        if ($wide) {
            return new KpiDataScope(null, $scope->institutional ? 'المؤسسة كلها' : 'كامل مجال القسم المالك');
        }
        if (! $source->sliceable()) {
            return 'هذا المؤشر لا يُنسب إلى الأقسام ويظهر للقسم المالك والإدارة العليا فقط.';
        }

        return new KpiDataScope($scope->departments, 'أقسامك');
    }

    /** @return list<mixed> */
    public function list(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return is_array($decoded) ? array_values($decoded) : [];
    }
}
