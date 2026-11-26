<?php

namespace App\Services\Orphans\Registration;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Where an AIMS record came from in the old system (legacy report §9.1 R8): the original row id per source table,
 * for audit and for a re-run of the migration without duplicates. Ids and review flags only — never names,
 * identity numbers or other personal data. The old `family_id` repeats, so it is kept on the household
 * (`households.legacy_file_no`), not here as a key.
 */
final class LegacyReferences
{
    public const SYSTEM = 'alamal_v1';

    public const SUBJECTS = ['persons', 'households', 'household_members', 'orphans', 'orphan_parent_links', 'orphan_guardianships', 'registry_documents', 'portal_applications'];

    /** Review flags a migration may attach (e.g. a duplicated identity group or a doubtful death date). */
    public const FLAGS = ['duplicate_id_group', 'placeholder_id', 'duplicate_parent_role', 'maternal_date_conflict', 'household_without_members', 'member_without_household',
        'truncated_name', 'family_level_guardian', 'unclassified_attachment'];

    /**
     * Records the link once; a repeated call returns the AIMS id already linked to that source row.
     *
     * @param  list<string>  $flags
     */
    public function record(string $sourceTable, string $sourceId, string $subjectType, int $subjectId, array $flags = [], ?int $actorId = null): int
    {
        if (! in_array($subjectType, self::SUBJECTS, true)) {
            throw new InvalidArgumentException("Unknown subject type {$subjectType}");
        }
        if ($unknown = array_diff($flags, self::FLAGS)) {
            throw new InvalidArgumentException('Unknown legacy flags: '.implode(', ', $unknown));
        }
        DB::table('registry_legacy_references')->insertOrIgnore([
            'source_system' => self::SYSTEM, 'source_table' => $sourceTable, 'source_id' => $sourceId, 'subject_type' => $subjectType,
            'subject_id' => $subjectId, 'flags' => $flags === [] ? null : json_encode(array_values(array_unique($flags))), 'created_by' => $actorId, 'created_at' => now(),
        ]);

        return (int) $this->find($sourceTable, $sourceId, $subjectType);
    }

    public function find(string $sourceTable, string $sourceId, string $subjectType): ?int
    {
        $id = DB::table('registry_legacy_references')->where(['source_system' => self::SYSTEM, 'source_table' => $sourceTable, 'source_id' => $sourceId, 'subject_type' => $subjectType])
            ->value('subject_id');

        return $id === null ? null : (int) $id;
    }
}
