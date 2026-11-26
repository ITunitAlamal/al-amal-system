<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Configuration\GazaGeography;
use Illuminate\Console\Command;

class ImportGazaAddresses extends Command
{
    protected $signature = 'addresses:import-gaza {file?} {--dry-run} {--user=}';

    protected $description = 'Import Gaza geographic reference nodes into existing System Constants';

    /**
     * Execute the console command.
     */
    public function handle(GazaGeography $geography): int
    {
        $actor = $this->option('user') ? User::find((int) $this->option('user')) : null;
        if ($this->option('user') && ! $actor?->isSystemAdmin()) {
            $this->error('An active system administrator is required.');

            return self::FAILURE;
        }
        $path = $this->argument('file') ?: base_path('AIMS_Gaza_Easy_Address_Selection.xlsx');
        try {
            $this->line(json_encode($geography->import($path, $actor?->id, (bool) $this->option('dry-run')), JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
