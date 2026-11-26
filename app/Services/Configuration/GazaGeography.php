<?php

namespace App\Services\Configuration;

use App\Services\AuditService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use ZipArchive;

final class GazaGeography
{
    public const TYPES = ['governorates', 'cities', 'neighborhoods', 'subareas'];

    public const GOVERNORATES = ['شمال غزة', 'غزة', 'الوسطى', 'خان يونس', 'رفح'];

    public const FIELDS = ['governorate_value_id', 'locality_value_id', 'area_value_id', 'subarea_value_id'];

    private ?Collection $loaded = null;

    public function nodes(): Collection
    {
        return $this->loaded ??= DB::table('master_data_values as v')
            ->join('master_data_types as t', 't.id', '=', 'v.type_id')->join('master_data_groups as g', 'g.id', '=', 't.group_id')
            ->whereIn('t.code', self::TYPES)->whereNotNull('v.metadata')
            ->orderBy('v.sort_order')->orderBy('v.id')
            ->get(['v.*', 't.code as type_code', 't.is_active as type_active', 'g.is_active as group_active'])
            ->filter(fn (object $node): bool => isset(json_decode($node->metadata, true)['geography']))->keyBy('id');
    }

    public function active(object $node): bool
    {
        $seen = [];
        do {
            if (isset($seen[$node->id]) || ! $node->is_active || ! $node->type_active || ! $node->group_active
                || ($node->effective_from && $node->effective_from > today()->toDateString())
                || ($node->effective_to && $node->effective_to < today()->toDateString())) {
                return false;
            }
            $seen[$node->id] = true;
            if ($node->parent_id === null) {
                return $node->type_code === 'governorates';
            }
            $node = $this->nodes()->get($node->parent_id);
        } while ($node);

        return false;
    }

    public function children(?int $parent): array
    {
        return $this->nodes()->filter(fn (object $node): bool => ($node->parent_id === null ? $parent === null : (int) $node->parent_id === $parent) && $this->active($node))
            ->map(fn (object $node): array => ['id' => $node->id, 'name_ar' => $node->name_ar, 'parent_id' => $node->parent_id, 'type' => $node->type_code])->values()->all();
    }

    /** Validate geography writes made through the existing constants controller. */
    public function validateNode(string $type, array $data, ?object $before = null): array
    {
        $level = array_search($type, self::TYPES, true);
        $parentId = array_key_exists('parent_id', $data) ? $data['parent_id'] : $before?->parent_id;
        $parent = $parentId ? $this->nodes()->get($parentId) : null;
        if ($level === false || ($level === 0 ? $parentId !== null : ! $parent || $parent->type_code !== self::TYPES[$level - 1]
            || (! $this->active($parent) && (int) $before?->parent_id !== (int) $parentId))) {
            throw ValidationException::withMessages(['parent_id' => 'اختر أبًا فعالًا من المستوى الجغرافي السابق.']);
        }
        if ($level === 0 && (! in_array($data['name_ar'], self::GOVERNORATES, true)
            || $this->nodes()->contains(fn (object $node): bool => $node->type_code === 'governorates' && $node->name_ar === $data['name_ar'] && (int) $node->id !== (int) $before?->id))) {
            throw ValidationException::withMessages(['name_ar' => 'أسماء محافظات غزة ثابتة ولا يجوز تكرارها.']);
        }
        if ($before && (int) $before->parent_id !== (int) $parentId) {
            $referenced = DB::table('master_data_values')->where('parent_id', $before->id)->exists();
            foreach (['employee_addresses', 'aims_properties', 'aims_rental_tenants'] as $table) {
                $referenced = $referenced || DB::table($table)->where(function ($query) use ($before): void {
                    foreach (self::FIELDS as $field) {
                        $query->orWhere($field, $before->id);
                    }
                })->exists();
            }
            if ($referenced) {
                throw ValidationException::withMessages(['parent_id' => 'العقدة مرتبطة بعناوين أو أبناء؛ أضف عقدة بديلة للحفاظ على العلاقات السابقة.']);
            }
        }
        $metadata = $before ? json_decode($before->metadata ?? '{}', true) : [];
        $metadata['geography'] = [...($metadata['geography'] ?? []), 'level' => $level, 'managed_by_admin' => true,
            'verification_status' => $data['verification_status'] ?? ($metadata['geography']['verification_status'] ?? 'unverified'),
            'verification_note' => $data['verification_note'] ?? ($metadata['geography']['verification_note'] ?? null),
            'location_type' => $data['location_type'] ?? ($metadata['geography']['location_type'] ?? null)];
        if ($metadata['geography']['verification_status'] === 'verified' && empty($metadata['geography']['verification_note'])) {
            throw ValidationException::withMessages(['verification_note' => 'أدخل مرجع التحقق قبل وصف العقدة بأنها موثقة.']);
        }

        return ['parent_id' => $parentId, 'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
    }

    /** Read only the workbook's data sheet; descriptions are data, never instructions. */
    public function readWorkbook(string $path): array
    {
        $zip = new ZipArchive;
        if (! is_file($path) || filesize($path) > 20000000 || $zip->open($path) !== true) {
            throw new \RuntimeException('Cannot open the address workbook.');
        }
        try {
            $readXml = function (string $entry) use ($zip): \SimpleXMLElement {
                $raw = $zip->getFromName($entry);
                if ($raw === false || strlen($raw) > 20000000 || str_contains($raw, '<!DOCTYPE')) {
                    throw new \RuntimeException('Invalid workbook XML.');
                }
                $xml = simplexml_load_string($raw, \SimpleXMLElement::class, LIBXML_NONET);
                if ($xml === false) {
                    throw new \RuntimeException('Invalid workbook XML.');
                }

                return $xml;
            };
            $sheetId = null;
            foreach ($readXml('xl/workbook.xml')->xpath('//*[local-name()="sheet"]') as $sheet) {
                if ((string) $sheet['name'] === 'بيانات النظام') {
                    $sheetId = (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                }
            }
            $entry = null;
            foreach ($readXml('xl/_rels/workbook.xml.rels')->xpath('//*[local-name()="Relationship"]') as $relation) {
                if ((string) $relation['Id'] === $sheetId && ! isset($relation['TargetMode'])) {
                    $target = (string) $relation['Target'];
                    $entry = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target;
                }
            }
            if (! $entry || str_contains($entry, '..')) {
                throw new \RuntimeException('Missing system data sheet.');
            }
            $shared = [];
            if ($zip->locateName('xl/sharedStrings.xml') !== false) {
                foreach ($readXml('xl/sharedStrings.xml')->xpath('//*[local-name()="si"]') as $item) {
                    $shared[] = implode('', array_map('strval', $item->xpath('.//*[local-name()="t"]')));
                }
            }
            $records = [];
            foreach ($readXml($entry)->xpath('//*[local-name()="sheetData"]/*') as $row) {
                $cells = [];
                foreach ($row->xpath('./*[local-name()="c"]') as $cell) {
                    $value = (string) ($cell->xpath('./*[local-name()="v"]')[0] ?? '');
                    $cells[preg_replace('/[0-9]/', '', (string) $cell['r'])] = match ((string) $cell['t']) {
                        's' => $shared[(int) $value] ?? '',
                        'inlineStr' => implode('', array_map('strval', $cell->xpath('.//*[local-name()="t"]'))),
                        default => $value,
                    };
                }
                if (($cells['A'] ?? '') !== '' && $cells['A'] !== 'id') {
                    $records[] = $cells;
                }
            }

            return $records;
        } finally {
            $zip->close();
        }
    }

    public function import(string $path, ?int $actor = null, bool $dryRun = false): array
    {
        $levels = ['محافظة' => 0, 'تجمع' => 1, 'حي / منطقة' => 2, 'منطقة فرعية' => 3];
        $rows = $this->readWorkbook($path);
        $source = [];
        foreach ($rows as $order => $row) {
            if (! preg_match('/^GAZ-[0-9]+$/D', $row['A']) || isset($source[$row['A']]) || ! isset($levels[$row['C'] ?? '']) || empty($row['E'])) {
                throw new \RuntimeException('Invalid or duplicate geographic identifier.');
            }
            $source[$row['A']] = [...$row, 'level' => $levels[$row['C']], 'order' => $order];
        }
        $roots = [];
        foreach ($source as $id => $row) {
            if ($row['level'] === 0) {
                if ($row['B'] !== 'GAZA') {
                    throw new \RuntimeException('Unexpected geographic root.');
                }
                $roots[] = $row['E'];
            } elseif (! isset($source[$row['B']]) || $source[$row['B']]['level'] !== $row['level'] - 1) {
                throw new \RuntimeException('Invalid geographic parent: '.$id);
            }
        }
        if (count($roots) !== 5 || array_diff(self::GOVERNORATES, $roots)) {
            throw new \RuntimeException('The five Gaza governorates must match the approved display names.');
        }
        $summary = ['source_records' => count($source), 'levels' => array_count_values(array_column($source, 'level')), 'created' => 0, 'updated' => 0, 'preserved' => 0];
        if ($dryRun) {
            return $summary;
        }

        return DB::transaction(function () use ($source, $summary, $path, $actor): array {
            $group = DB::table('master_data_groups')->where('code', 'locations')->first();
            if (! $group) {
                throw new \RuntimeException('Existing locations constants group is required.');
            }
            $types = DB::table('master_data_types')->whereIn('code', self::TYPES)->pluck('id', 'code')->all();
            if (! isset($types['subareas'])) {
                $types['subareas'] = DB::table('master_data_types')->insertGetId(['group_id' => $group->id, 'code' => 'subareas', 'name_ar' => 'المناطق الفرعية', 'is_active' => true, 'created_by' => $actor, 'updated_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
            }
            $hash = hash_file('sha256', $path);
            uasort($source, fn (array $a, array $b): int => [$a['level'], $a['order']] <=> [$b['level'], $b['order']]);
            $ids = [];
            foreach ($source as $external => $row) {
                $typeId = $types[self::TYPES[$row['level']]] ?? throw new \RuntimeException('Existing geography constant type missing.');
                $code = 'gaza_'.strtolower(str_replace('-', '_', $external));
                $old = DB::table('master_data_values')->where('code', $code)->lockForUpdate()->first();
                $parent = $row['level'] === 0 ? null : $ids[$row['B']];
                $metadata = $old ? json_decode($old->metadata ?? '{}', true) : [];
                if ($old && ((int) $old->type_id !== (int) $typeId || ! isset($metadata['geography']['external_id']) || $metadata['geography']['external_id'] !== $external)) {
                    throw new \RuntimeException('External identifier collision: '.$external);
                }
                if ($old && (int) $old->parent_id !== (int) $parent) {
                    throw new \RuntimeException('Existing hierarchy differs; review reparenting in System Constants: '.$external);
                }
                if ($old && (($metadata['geography']['managed_by_admin'] ?? false) || ($metadata['geography']['source_sha256'] ?? null) === $hash)) {
                    $ids[$external] = $old->id;
                    $summary['preserved']++;

                    continue;
                }
                $metadata['geography'] = ['external_id' => $external, 'level' => $row['level'], 'location_type' => $row['D'],
                    'source' => basename($path), 'source_sha256' => $hash, 'source_verification' => $row['H'] ?? '',
                    'verification_status' => 'unverified', 'managed_by_admin' => false];
                $values = ['type_id' => $typeId, 'code' => $code, 'name_ar' => preg_replace('/\s+/u', ' ', trim($row['E'])), 'parent_id' => $parent,
                    'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'sort_order' => $row['order'], 'updated_by' => $actor, 'updated_at' => now()];
                if ($old) {
                    DB::table('master_data_values')->where('id', $old->id)->update($values);
                    $ids[$external] = $old->id;
                    $summary['updated']++;
                } else {
                    $ids[$external] = DB::table('master_data_values')->insertGetId([...$values, 'is_active' => true, 'created_by' => $actor, 'created_at' => now()]);
                    $summary['created']++;
                }
            }
            app(AuditService::class)->recordRow('geography.imported', 'master_data_groups', $group->id, null, [...$summary, 'sha256' => $hash], $actor);
            $this->loaded = null;

            return $summary;
        });
    }
}
