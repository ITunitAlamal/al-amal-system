# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

AIMS (Al Amal Institute Management System) — the internal ERP of جمعية معهد الأمل للأيتام (Gaza). Laravel 13 (PHP 8.4 via Herd) + Inertia 3 + Vue 3 + Tailwind 4 + Vite 8, on PostgreSQL. The whole UI is Arabic and RTL; code, comments and commit messages are English. The owner writes in Arabic — reply in Arabic.

## This working copy is the live system

There is no separate staging copy. Treat every command as running against production.

- `.env` is `APP_ENV=production` and points at the live database `al_amal_db` (real staff, about 37,500 orphan records, an append-only audit log).
- Staff use the app over the LAN from **this folder**: `php artisan serve --host=172.16.10.42 --port=8080`. A saved PHP file is live on the next request.
- The Windows task "AIMS Laravel Scheduler" runs `php artisan schedule:run` every minute from this folder (see `routes/console.php`).
- The frontend is served from `public/build`. Vue/CSS changes reach users only after `npm run build`. Do not leave `npm run dev` running: it writes `public/hot`, which points every user's browser at a dev server on this machine.
- Never run `migrate:fresh`, `migrate:refresh`, `migrate:rollback`, `db:wipe` or `db:seed`. Take a database backup before `php artisan migrate`; most migrations cannot be undone (see Migrations).
- Do not put sample or test data in the database. Check a change by reading real records instead.

## Commands

`php`, `composer` and `npm` are on the PowerShell PATH. `php` is **not** available in Git Bash — use PowerShell for artisan.

```powershell
npm run build                              # required after any change under resources/js or resources/css
vendor\bin\pint --dirty                    # format changed PHP files
php artisan route:list --path=finance      # routes of one area
php artisan migrate:status                 # see Migrations for the one intentionally pending entry
php artisan test --filter=ClassOrMethod    # one PHPUnit feature test (in-memory SQLite, per phpunit.xml)
node --test tests/uiNavigation.test.mjs    # one frontend support test (node:test, no bundler)
```

PHPUnit only stays off the live database because `phpunit.xml` overrides the connection. Never run tests while the config is cached (`php artisan config:cache`) — the cached production connection would win.

The owner's standing rules: apply changes directly, do not add test files, do not run the test suites, and do not use screenshots or browser automation to verify. Verify by reading code, routes and real data. Keep screens simple: few fields, one-step actions, automatic bookkeeping.

Git: work on `develop`; `master` is fast-forwarded to it. There is no remote. Commit only when asked.

## Architecture

### Request path

`routes/web.php` (plus one file per module required at its end: `finance.php`, `aims_orphans.php`, `aims_rentals.php`, `aims_employees.php`, `aims_organization.php`, `guardians.php`, three `attendance_*.php`) → thin controllers in `app/Http/Controllers` → services in `app/Services/<Module>`. Business rules live in the services.

Data access is mostly the query builder (`DB::table(...)`), not Eloquent: about 240 tables and only 35 models. Do not assume a model exists for a table; follow the style of the service you are editing.

Controllers return `Inertia::render('Area/Page')`, resolved to `resources/js/Pages/Area/Page.vue` by `resources/js/aims.js`. `HandleInertiaRequests` shares `menu`, `auth.user`, `auth.permissions`, `notifications`, `workCounters` and `flash` with every staff page. Only the login page, error pages and the print views (payslip, finance report) are still Blade.

### Three audiences, three boundaries

- **Staff** — `web` guard, `users` table, `AppLayout.vue`.
- **Beneficiary portal** — `guardian` guard, `guardians` table, routes under `/guardian`, `PortalLayout.vue` / `GuardianLayout.vue`. `PortalHostBoundary` (first middleware) separates the two hosts and their session cookies when `PORTAL_HOST` / `STAFF_HOST` are set (`config/portal.php`); unset, both run on one host.
- **Field devices** — stateless token + device API under `/api/field/v1` (`routes/field_api.php`, `AuthenticateFieldDevice`), consumed by the Electron app in `field_client/`.

### Authorization

There are no Laravel policies. Roles are assigned per department in `department_user_roles` (a null department means organization-wide). The checks are on `App\Models\User`:

- `hasPermission($code, $departmentId)` — own roles, **or** headship of the department that owns the permission (`User::DEPARTMENT_HEAD_PERMISSIONS`), **or** a scoped delegation (`Organization\ScopedDelegations`).
- `system_admin` is a *technical* role. It passes `hasPermission`, but not `holdsDecisionPermission` (approve/reject business decisions) or `holdsConfidentialPermission` (pay, bank accounts). Use those two for decisions and confidential data.
- A department head is the user in `departments.head_user_id` who also holds the `department_head` role in that department; one person may head several.

Each module wraps these in its own access class (`FinanceGuard`, `OrphanAccess`, `AttendanceAccess`, `RentalsAccess`, `KpiAccess`, `CorrespondenceAccess`, `Hr\EmployeeAccess`). Use the module's class rather than repeating role checks in a controller.

### Navigation

One sidebar, built per user by `Configuration\NavigationBuilder`. `NavigationRegistry::ITEMS` is the canonical list: each real page has one entry fixing its route and its visibility rule; `NavigationAccessRules` implements the rules so the menu mirrors the page's own authorization. The `navigation_items` table only stores what the administrator may change (label, icon, group, order, hidden). A new page needs a route, a registry entry and a rule that matches the controller's check.

### Workflow engine

Every approval chain (leave, maintenance, purchase, rentals, pay change, structure change, attendance permission, shift swap, correspondence, generic requests) runs on `Services\Workflow\WorkflowEngine` over the `workflow_*` tables. Definitions, steps and transitions are **data**, installed by migrations and edited in the workflow designer (`WorkflowPublishingService`), not code.

- A module record joins the engine by implementing `WorkflowSubject` (`beforeMove`, `afterMove`, `conditionContext`, `url`, `titles`) and registering in `WorkflowSubjects::HANDLERS`. Its effect runs inside the engine's transaction, so a failed effect undoes the move.
- `act()` takes an `expectedRevision`; pass the revision the user saw so concurrent decisions are refused.
- `AssigneeResolver` picks who acts on a step, `ConditionEvaluator` evaluates transition conditions, and `workflow:check-sla` handles deadlines and escalation.

### Audit trail («سجل النظام»)

Write audit rows through `AuditService` (`record`, `recordRow`, `event`). A PostgreSQL trigger fills request context from the session setting `aims.audit_ctx`, set by the `AuditContext` middleware for requests and by `AppServiceProvider` for artisan commands. `audit_logs` is append-only at the database level — UPDATE, DELETE and TRUNCATE raise an error — and `audit:seal` chains rows with hashes. Refused requests (403, throttled sign-in) are recorded from `bootstrap/app.php`.

### Background work

Everything periodic is a scheduled artisan command in `routes/console.php`: attendance sync and recalculation, the smart-import pipeline (`orphans:import-process`, resumable chunks), audit sealing, SLA checks, KPI snapshots, rental alerts, finance access expiry. Attendance devices (ZKTeco) are read through the read-only Python bridge `attendance_bridge/zk_readonly.py`; pulling is off unless `ATTENDANCE_ZK_SYNC_ENABLED` is set.

### Migrations

- The date prefix is an ordering key, not a real date (the latest run to `2026_11_30_0000NN`). A new migration must sort after the last existing file.
- Migrations also install data: permissions, roles, navigation entries, workflow definitions, KPI definitions.
- They use PostgreSQL-specific SQL (partial indexes, `jsonb`, triggers), and many `down()` methods throw on purpose to protect records.
- `2026_11_21_000001_create_correspondence_editions_table` is intentionally **pending**. The correspondence editions code checks `Schema::hasTable('correspondence_editions')` and stays hidden. Do not run it without the owner's decision — note that a plain `php artisan migrate` would run it.

### Frontend

- Shared building blocks are in `resources/js/Components` (page header, dialogs, status badge and stepper, pagination, filters) and composables in `resources/js/Support`. Reuse them before writing page-local versions.
- The design system is `resources/css/aims.css` (Tailwind 4 tokens taken from the logo: navy, blue, gold); `docs/ui-ux/AIMS_DESIGN_SYSTEM.md` describes it. There is no second component library.
- `NormalizeDigits` converts Arabic-Indic and Persian digits in submitted fields to 0–9 before validation (passwords excepted), so validation rules can assume ASCII digits.

## Requirements and reference documents

- `references_documents/` — the approved SRS and the six department requirement documents (Word files). These are the source of truth for business rules.
- `AIMS_*.md` in the repository root — the owner's specifications for the orphans 2.0 module, smart import, beneficiary portal, registration fields and the role dashboards.
- `docs/` — per-module reports and open decisions: `docs/finance/`, `docs/registration/` (00–06), `docs/aims2/`, `docs/audits/`, `docs/ui-ux/`, plus `docs/AIMS_RBAC_MATRIX.md` and `docs/AIMS_AUDIT_TRAIL.md`.
- `.agents/skills/` — Laravel Boost guideline skills (Laravel, Inertia + Vue, Tailwind) installed for coding agents.
