<?php

/*
 * Beneficiary portal inside AIMS. When both hosts are set, each host only serves its own routes and the portal
 * uses its own host-only session cookie, so staff and beneficiary sessions never mix. Without hosts (local or
 * IP access) both areas keep working on one host under /guardian.
 */
return [
    'host' => env('PORTAL_HOST'),            // e.g. portal.al-amal.ps
    'staff_host' => env('STAFF_HOST'),       // e.g. system.al-amal.ps
    'session_cookie' => env('PORTAL_SESSION_COOKIE', 'aims_portal_session'),
    'otp_minutes' => 10,
    'otp_attempts' => 5,
    // Optional caps on sign-up codes sent by SMS (AC-11), set by the owner; empty or 0 leaves a cap off.
    'signup_sms_per_ip_hour' => env('PORTAL_SIGNUP_SMS_PER_IP_HOUR'),
    'signup_sms_per_day' => env('PORTAL_SIGNUP_SMS_PER_DAY'),
];
