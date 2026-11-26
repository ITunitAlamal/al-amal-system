# Changes and deployment

Implemented in the existing module:

- Shared authorized mailbox query, new task/history boxes, counts, filters, actor search, task deadlines and urgency labels.
- Scoped personal dashboard and top-bar correspondence counts; removed cancelled/expired task counting and added overdue/filter links.
- Fixed stale-department membership fallback and rechecked current action access before read receipts.
- Exposed existing return/refusal actions with attachments and mandatory reasons; retained simple compose/send/draft UX.
- Separate authorized A4 official-print page and technical audit appendix; normal print excludes internal routing notes and later private attachments.
- Explicit private overdue notifications through the existing NotificationCenter.
- Extended isolated PostgreSQL feature tests, two-process sequence contention test, Vue SSR escaping/CSS checks, and repaired the stale JS action-confirmation test name.

Primary changed paths: `app/Http/Controllers/CorrespondenceController.php`, `app/Services/Correspondence/{CorrespondenceAccess,CorrespondenceRegistry}.php`, `app/Console/Commands/CorrespondenceReconcile.php`, `app/Services/Dashboard/PersonalWorkspace.php`, `app/Http/Middleware/HandleInertiaRequests.php`, `routes/web.php`, `resources/js/Pages/Correspondence/{Index,Show,Print}.vue`, `resources/js/Pages/Home.vue`, and related tests.

No new application migration or dependency change. No production migration/seed or role assignment executed. Only the isolated test setup conditionally applies the existing workflow hold migration. Do not run all migrations to deploy these code changes.

Deployment/rollback plan: review and preserve the current uncommitted work, archive the exact predeployment source and built assets, deploy only reviewed correspondence changes plus rebuilt assets, and check authorized role flows in an approved acceptance environment. Roll back those code/assets together if needed; do not delete or reverse correspondence records. Any future schema change requires a separately reviewed backup and migration plan. No production deployment is claimed here.
