<?php

return [
    'auto_direction_enabled' => env('ATTENDANCE_AUTO_DIRECTION_ENABLED', false),
    // Recalculates today and yesterday every five minutes; without it imported punches never reach the ledger.
    'calculation_schedule_enabled' => (bool) env('ATTENDANCE_CALCULATION_SCHEDULE_ENABLED', true),
    'zk_sync_enabled' => env('ATTENDANCE_ZK_SYNC_ENABLED', false),
    'zk_allowed_hosts' => env('ATTENDANCE_ZK_ALLOWED_HOSTS', ''),
    'zk_allowed_ports' => env('ATTENDANCE_ZK_ALLOWED_PORTS', '4370'),
    'agent_destinations' => json_decode(env('ATTENDANCE_AGENT_DESTINATIONS', '[]'), true),
    'zk_python' => env('ATTENDANCE_ZK_PYTHON', 'python3'),
    'health_probe_enabled' => env('ATTENDANCE_HEALTH_PROBE_ENABLED', false),
    'agent_id' => env('ATTENDANCE_AGENT_ID', 'local'),
    'health_probe_interval_seconds' => (int) env('ATTENDANCE_HEALTH_PROBE_INTERVAL_SECONDS', 30),
    'health_probe_timeout_seconds' => (int) env('ATTENDANCE_HEALTH_PROBE_TIMEOUT_SECONDS', 10),
    'health_probe_freshness_seconds' => (int) env('ATTENDANCE_HEALTH_PROBE_FRESHNESS_SECONDS', 90),
    'agent_freshness_seconds' => (int) env('ATTENDANCE_AGENT_FRESHNESS_SECONDS', 45),
    'sync_freshness_seconds' => (int) env('ATTENDANCE_SYNC_FRESHNESS_SECONDS', 900),

    // «تبديل المناوبة» limits live in attendance_module_settings (AttendanceSettings), managed from «الحضور والانصراف».
    // «طلب إذن»: attachments are private files of at most this size.
    'permission_attachment_kb' => (int) env('ATTENDANCE_PERMISSION_ATTACHMENT_KB', 5120),
];
