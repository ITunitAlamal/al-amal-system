<?php

return [
    'calculation_schedule_enabled' => env('ATTENDANCE_CALCULATION_SCHEDULE_ENABLED', false),
    'schedule_enabled' => env('ATTENDANCE_SCHEDULE_ENABLED', false),
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
];
