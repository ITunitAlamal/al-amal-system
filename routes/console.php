<?php

use App\Services\Configuration\SchedulerHeartbeat;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Workflow deadlines: requires cron invoking schedule:run every minute.
Schedule::command('workflow:check-sla')->everyFifteenMinutes()->withoutOverlapping(10);

// KPI readings on the first of each month: last month's figures and today's state, kept for trends.
Schedule::command('kpi:snapshot --previous-month')->monthlyOn(1, '00:30')->withoutOverlapping(30);

/*
 * Attendance (runs on this machine, the trusted LAN agent; Windows Task Scheduler calls schedule:run every minute,
 * see attendance_bridge/windows/Install-AimsScheduler.ps1).
 * - Device pull: checked every minute so an HR "sync now" request runs within a minute; each device's own
 *   sync_interval_seconds decides the periodic pulls. The command itself does nothing while ATTENDANCE_ZK_SYNC_ENABLED is off.
 * - Today and yesterday are recalculated every five minutes (open days close, late punches land, absences appear).
 * - Automatic direction corrections only when ATTENDANCE_AUTO_DIRECTION_ENABLED is on (the command checks it).
 * All three share the device and recalculation locks, so they never overlap or race each other.
 */
Schedule::command('attendance:sync')->everyMinute()->withoutOverlapping(10);

if (config('attendance.calculation_schedule_enabled', true) === true) {
    Schedule::command('attendance:recalculate --recent')->everyFiveMinutes()->withoutOverlapping(10);
}

if (config('attendance.health_probe_enabled', false) === true) {
    Schedule::command('attendance:probe-devices')
        ->everyThirtySeconds()->withoutOverlapping(1);
}

Schedule::command('attendance:auto-correct --apply')->everyFiveMinutes()->withoutOverlapping(10);

Schedule::command('correspondence:reconcile')->everyFifteenMinutes()->withoutOverlapping(10);

// Private reminders of the personal To-Do list (one notice per reminder time, to the owner only).
Schedule::command('personal-tasks:remind')->everyFiveMinutes()->withoutOverlapping(5);

// Rentals: expire contracts past their end, renewal-window and overdue alerts (each sent once).
Schedule::command('rentals:alerts')->dailyAt('06:30')->withoutOverlapping(30);
Schedule::command('hr:document-alerts')->dailyAt('06:50')->withoutOverlapping(30);

// «الاستيراد الذكي»: continues large import batches (staging analysis or approved execution) in resumable chunks.
Schedule::command('orphans:import-process')->everyMinute()->withoutOverlapping(10)->runInBackground();

// Sponsorships: planned dues kept twelve months ahead (eligibility gate applies); one daily notice to the head about overdue referred dues.
Schedule::command('orphans:sponsorship-dues')->dailyAt('06:40')->withoutOverlapping(30);

// «سجل النظام»: seal new audit rows into the hash chain every minute; recompute the whole chain daily.
Schedule::command('audit:seal')->everyMinute()->withoutOverlapping(10);
Schedule::command('audit:seal --verify')->dailyAt('03:15')->withoutOverlapping(60);
// Dated finance grants and SoD exceptions end by themselves after their end date.
Schedule::command('finance:expire-access')->hourly()->withoutOverlapping(30);

// The last result of every command above, for «جاهزية الإعدادات» (SystemReadiness: scheduled commands failing). Keep last.
SchedulerHeartbeat::watch(Schedule::events());
