<?php

namespace App\Providers;

use App\Services\Attendance\AttendanceDeviceHealthProbe;
use App\Services\Attendance\AttendanceDeviceProber;
use App\Services\Orphans\Extensions\AidExtension;
use App\Services\Orphans\Extensions\CampaignExtension;
use App\Services\Orphans\Extensions\PortalSection;
use App\Services\Orphans\OrphanAccess;
use App\Services\Progress\TransactionTracker;
use App\Services\Validation\TypeSafePresenceVerifier;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Foundation\Console\ServeCommand;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator as InputValidator;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AttendanceDeviceProber::class, AttendanceDeviceHealthProbe::class);
        // One access resolver per request so role/capability lookups are cached across a page.
        $this->app->scoped(OrphanAccess::class);
        // One task inbox per request: the «مهامي» badge shared with every page reuses the list a page already read.
        $this->app->scoped(TransactionTracker::class);
        // Department modules that add sections to the orphan/guardian files or filters to the registry.
        $this->app->tag([PortalSection::class, AidExtension::class], 'orphans.profile_sections');
        foreach (['orphans.sponsorship_coverage', 'orphans.benefit_summaries', 'orphans.registry_filters', 'orphans.action_items'] as $tag) {
            $this->app->tag([AidExtension::class], $tag);
        }
        $this->app->tag([CampaignExtension::class], 'orphans.action_items');

        // "exists"/"unique" rules answer "no match" for text sent to an integer column instead of a PostgreSQL error.
        $this->app->extend('validation.presence', fn ($verifier, $app) => new TypeSafePresenceVerifier($verifier, $app['db']));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->translateRelativeDates();
        $this->keepTempDirectoryForServe();
        $this->auditContextForCommands();
    }

    /**
     * Audit rows written by artisan commands (scheduler, import worker, attendance sync…) are «مهمة خلفية»
     * with one correlation id per command run.
     */
    private function auditContextForCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }
        $command = null;
        $correlation = (string) Str::ulid();
        Event::listen(CommandStarting::class, function ($event) use (&$command): void {
            $command = $event->command;
        });
        Event::listen(ConnectionEstablished::class, function ($event) use (&$command, $correlation): void {
            if ($event->connection->getDriverName() !== 'pgsql') {
                return;
            }
            try {
                $event->connection->select("SELECT set_config('aims.audit_ctx', ?, false)", [json_encode(['o' => 'worker', 'r' => $command ? 'artisan:'.$command : null, 'c' => $correlation])]);
            } catch (\Throwable) {
                // Defaults apply.
            }
        });
    }

    /**
     * `artisan serve` gives the PHP server only the environment variables it lists. Without TEMP and TMP, PHP on
     * Windows falls back to C:\Windows for temporary files, so every upload fails («تعذر رفع ...») and tempnam() errors.
     */
    private function keepTempDirectoryForServe(): void
    {
        if ($this->app->runningInConsole()) {
            ServeCommand::$passthroughVariables = array_values(array_unique([...ServeCommand::$passthroughVariables, 'TEMP', 'TMP']));
        }
    }

    /**
     * Date rules such as before:today show «اليوم» in the message instead of the raw keyword;
     * a rule that compares with another field shows that field's Arabic name.
     */
    private function translateRelativeDates(): void
    {
        $words = ['today' => 'تاريخ اليوم', 'tomorrow' => 'تاريخ الغد', 'yesterday' => 'تاريخ الأمس', 'now' => 'الوقت الحالي'];

        foreach (['before', 'before_or_equal', 'after', 'after_or_equal', 'date_equals'] as $rule) {
            Validator::replacer($rule, function (string $message, string $attribute, string $rule, array $parameters, InputValidator $validator) use ($words): string {
                $parameter = (string) ($parameters[0] ?? '');
                $shown = $words[strtolower($parameter)]
                    ?? (strtotime($parameter) === false ? $validator->getDisplayableAttribute($parameter) : $parameter);

                return str_replace(':date', $shown, $message);
            });
        }
    }
}
