<?php

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

// Run only on the assigned trusted LAN agent; remote hosts require an approved private route.
if (config('attendance.schedule_enabled', false) === true) {
    Schedule::command('attendance:sync')
        ->everyMinute()->withoutOverlapping(10);
}

if (config('attendance.calculation_schedule_enabled', false) === true) {
    foreach ([now()->subDay()->toDateString(), now()->toDateString()] as $attendanceDate) {
        Schedule::command('attendance:recalculate', [$attendanceDate])
            ->everyFiveMinutes()->withoutOverlapping(10);
    }
}

if (config('attendance.health_probe_enabled', false) === true) {
    Schedule::command('attendance:probe-devices')
        ->everyThirtySeconds()->withoutOverlapping(1);
}

Schedule::command('correspondence:reconcile')->everyFifteenMinutes()->withoutOverlapping(10);

// Private reminders of the personal To-Do list (one notice per reminder time, to the owner only).
Schedule::command('personal-tasks:remind')->everyFiveMinutes()->withoutOverlapping(5);

// Rentals: expire contracts past their end, renewal-window and overdue alerts (each sent once).
Schedule::command('rentals:alerts')->dailyAt('06:30')->withoutOverlapping(30);

// Sponsorships: extend the dues to the rolling horizon and refresh regularity.
Schedule::command('orphans:sponsorship-schedules')->dailyAt('06:45')->withoutOverlapping(30);

// AIMS: registered biometric pull job (five-minute interval).
Schedule::command('attendance:sync')->everyFiveMinutes()->withoutOverlapping(10);
