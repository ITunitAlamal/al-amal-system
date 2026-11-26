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
];
