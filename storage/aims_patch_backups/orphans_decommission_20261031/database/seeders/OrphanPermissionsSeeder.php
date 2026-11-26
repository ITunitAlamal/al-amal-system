<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Services\Orphans\OrphanAccess;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class OrphanPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (OrphanAccess::CAPABILITIES as $code => $name) {
            Permission::firstOrCreate(['code' => 'orphans.guardians.'.$code], ['name' => $name]);
        }
        $ids = Permission::whereIn('code', array_map(fn ($code) => 'orphans.guardians.'.$code, array_keys(OrphanAccess::CAPABILITIES)))->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
    }
}
