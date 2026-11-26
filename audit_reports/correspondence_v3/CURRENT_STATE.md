# Current state — 2026-10-26 (Asia/Hebron)

Repository: `C:/Projects/al-amal-system`, existing uncommitted working tree. No replacement correspondence module, workflow engine, notification center, or navigation hierarchy was introduced.

- PHP 8.4.26, Laravel 13.36.0, inertia-laravel 3.0.0; Vue/server-renderer 3.5.44; Vite build reports 8.3.4. Composer direct versions and package metadata inspected.
- AGENTS.md and the installed Inertia, Laravel, testing and Tailwind skills read. `.ai/rules` absent.
- Boost was not exposed as a tool namespace in this chat. Its configured `php artisan boost:mcp` stdio endpoint was used directly: initialize succeeded; tools/list exposed search-docs; tools/call returned Inertia v3 documentation. Initial PowerShell piping produced a first-line JSON parse error; a UTF-8 Node stdio client corrected initialization. No configuration edits were needed.
- On continuation, the native Laravel Boost MCP tools became available. Direct `application-info` and `search-docs` calls succeeded and reconfirmed the installed versions and Inertia v3 documentation.
- Runtime route inspection: 13 correspondence routes initially, 14 after the authenticated print endpoint. Controllers enforce record/action authorization in addition to auth middleware.
- Metadata-only live schema inspection confirmed `departments.node_type` exists now. `workflow_versions.change_note` remains absent. Both correspondence migrations are applied at batch 60; migrate:status reported all current migrations applied. Earlier reports describing pending migrations are historical, not current evidence.
- No production migrations, seeds, application record writes, external messages, screenshots or browser automation were performed.

The existing PostgreSQL 18 test cluster in the user's temporary directory was stopped initially. It was started on loopback port 55439 and the exact test database was verified; users and correspondence tables were empty. The historical test schema lacked workflow hold columns. Test setup now applies only the existing hold migration when missing, after database/port/host assertions. No production fallback exists.

After the successful final test run, both test users and test correspondence counts were zero. At the continuation handover check, `pg_ctl status` reported the isolated server stopped again. The recorded passing results are from the actual completed run; no new database-writing test was attempted against the stopped server or redirected to production.

Evidence: `CorrespondenceController.php:25` (inbox), `:170` (show/actions), `:280` (print), `:325` (downloads); `CorrespondenceAccess.php:58` (membership), `:222` (mailbox); `CorrespondenceRegistry.php:296` (read receipt).
