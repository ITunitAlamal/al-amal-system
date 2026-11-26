<?php

namespace App\Providers;

use App\Services\Attendance\AttendanceDeviceHealthProbe;
use App\Services\Attendance\AttendanceDeviceProber;
use App\Services\Orphans\GuardianRegistryClient;
use App\Services\Orphans\UnavailableGuardianRegistry;
use App\Services\Validation\TypeSafePresenceVerifier;
use Illuminate\Foundation\Console\ServeCommand;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Validator as InputValidator;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(GuardianRegistryClient::class, UnavailableGuardianRegistry::class);
        $this->app->bind(AttendanceDeviceProber::class, AttendanceDeviceHealthProbe::class);

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
