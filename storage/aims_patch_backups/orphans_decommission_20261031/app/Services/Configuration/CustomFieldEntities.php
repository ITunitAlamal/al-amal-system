<?php

namespace App\Services\Configuration;

use Illuminate\Support\Facades\DB;

/**
 * Entities whose screens render dynamic fields.
 *
 * Only entities listed here are offered in the field designer, so an
 * administrator cannot define fields that no screen would ever show.
 */
final class CustomFieldEntities
{
    public const AIMS_REQUEST = 'aims_request';

    /** The guardian's profile in the orphans department (requirement Q05): values keyed by guardians.id. */
    public const ORPHAN_GUARDIAN = 'orphan_guardian';

    /** @var array<string, array{label: string, scoped_by: ?string}> */
    private const ENTITIES = [
        self::AIMS_REQUEST => ['label' => 'الطلبات والمعاملات', 'scoped_by' => 'workflow'],
        self::ORPHAN_GUARDIAN => ['label' => 'ملف الوصي (قسم الكفالات وشؤون الأيتام)', 'scoped_by' => null],
    ];

    /**
     * @return array<string, string> entity key => Arabic label
     */
    public function all(): array
    {
        return array_map(fn (array $entity): string => $entity['label'], self::ENTITIES);
    }

    public function isKnown(string $entityType): bool
    {
        return array_key_exists($entityType, self::ENTITIES);
    }

    /**
     * Sub-types an entity's fields can be limited to. An empty key means "all".
     *
     * @return array<string, string>
     */
    public function scopes(string $entityType): array
    {
        $scopes = ['' => 'جميع الأنواع'];

        if ((self::ENTITIES[$entityType]['scoped_by'] ?? null) === 'workflow') {
            // Module workflows (e.g. leave) keep their data in their own records, not in dynamic fields.
            foreach (DB::table('workflow_definitions')->where('subject_type', 'aims_request')->orderBy('name')->get(['code', 'name']) as $definition) {
                $scopes[$definition->code] = $definition->name;
            }
        }

        return $scopes;
    }

    /**
     * Step codes a scoped field may be attached to, so it is filled during that step.
     *
     * @return array<string, string>
     */
    public function steps(string $entityType, string $scopeKey): array
    {
        if ($scopeKey === '' || (self::ENTITIES[$entityType]['scoped_by'] ?? null) !== 'workflow') {
            return [];
        }

        return DB::table('workflow_steps as s')
            ->join('workflow_versions as v', 'v.id', '=', 's.workflow_version_id')
            ->join('workflow_definitions as d', 'd.id', '=', 'v.workflow_definition_id')
            ->where('d.code', $scopeKey)
            ->where('s.step_type', '!=', 'start')
            ->orderBy('s.sort_order')
            ->pluck('s.name', 's.code')
            ->all();
    }
}
