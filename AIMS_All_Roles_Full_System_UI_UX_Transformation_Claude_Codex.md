# MASTER IMPLEMENTATION PROMPT — AIMS ERP, All-Roles UI/UX Transformation

**Target agents:** Claude Code / OpenAI Codex in VS Code
**Project:** Al-Amal Institute for Orphans ERP (AIMS), `C:\Projects\al-amal-system`
**Deliverable:** Working, production-quality upgrades to **all existing AIMS interfaces and role-specific dashboards**, not mockups or a separate demo app.
**Instructions language:** English. **All user-visible application language:** Arabic, natural institutional terminology, RTL (including validation, empty states, errors, labels, charts, legends, exports, tooltips, dates and accessible names).

## 0. Primary objective, constraints and non-negotiable decisions

Act simultaneously as a senior product designer, information architect, accessibility specialist, Laravel engineer, frontend engineer and QA lead. Make AIMS feel like a cohesive, trustworthy, modern enterprise system serving institute executives, department heads, staff, field operators and beneficiaries. Prioritize clarity, speed, comprehensibility and decision support over ornamental dashboard components.

**This is a UI/UX and presentation-layer modernization project, NOT a replacement of the ERP or an excuse to rewrite business logic.** Inspect and reuse the actual Laravel/frontend stack, component library, authentication, policies, route naming, service APIs, PostgreSQL schema, existing shell and design tokens. Do not assume Vue vs Blade vs React; discover the actual code. Do not switch PostgreSQL to MySQL or create another system. Keep production data, migrations, shared `departments` tree (`parent_id`, `node_type`), distinct `locations`, and all other shared integrations intact.

Preserve the current architectural boundaries: staff ERP at `system.al-amal.ps`; beneficiary portal at `portal.al-amal.ps` as a logically isolated module of the same Laravel modular monolith; public website at `al-amal.ps` is a separate application. Do not add cross-application navigation that confers unauthorized access. No generic broad access to orphan/health information.

The Orphans & Sponsorships section must retain exactly SIX primary menu areas: **الرئيسية، إدارة الأيتام والأوصياء، الطلبات والمراجعات، الكفالات والمساعدات، الحملات الإغاثية، التقارير والإحصائيات**. Smart Import (`الاستيراد الذكي`) is a contextual quick action within existing sections, **not a seventh top-level menu**. Other ERP departments keep their existing legitimate navigation; simplify intelligently without hiding real functionality.

**Exclusive executive director authority:** Only the executive director can approve initial relief-campaign beneficiary lists or approve modifications to already approved lists. Department head, ordinary staff, and system administrator cannot bypass this via UI or API. UI shall show clear locked states, version history, comparison and permission-appropriate requests for amendments; do not change backend approval invariants.

Registry import creates eligible records in real operational orphan/guardian/family tables with explicit **«معتمد مبدئيًا»** status, not exclusively staging; provisional guardianship is NOT legal verification, and may not grant portal access to children's official records. A shared family guardian may be proposed/linked provisionally to children when relationship evidence is consistent, but legal verification remains child-specific. Never depict or implement indiscriminate automatic approvals. Actual aid benefits require evidence of fulfillment, not nomination.

## 1. Audit-first workflow — mandatory before changing files

1. Audit project structure, versions, build commands, layouts, design system/assets, installed icons, fonts, existing chart libraries, routes, pages and components; inspect authorization boundaries, API payloads and data contracts.
2. Inventory **all existing screens**, grouped by organizational department, user role, public beneficiary portal and core shared functionality. Identify which are frequently used, visually inconsistent, overly dense, inaccessible or missing states. Do not guess missing modules into existence.
3. Produce `docs/UI_UX_SCREEN_INVENTORY.md` and `docs/UI_UX_IMPLEMENTATION_PLAN.md`: screen, role, purpose, decision it supports, data source, access restrictions, current issues, proposed design, target component reuse, implementation phase and verification criteria.
4. Capture baseline snapshots/screenshots where the local environment supports it and record actual layout/content overflow, keyboard issues and performance. Never label demo data as real.
5. Inspect existing brand logo/color values and use their actual assets; do not invent branding. Establish a scoped component strategy so improvement does not break unrelated modules.
6. Present concise phase plan, then implement progressively and verify actual screens. Avoid prolonged planning-only output; proceed to code after audit if workspace is writable.

## 2. Universal dashboard design grammar — apply to each role

Use the following framework **for every dashboard**, adapting purpose and data, not cloning the same KPIs for everyone:

> Design a **light**, modern, enterprise decision-support dashboard for **[actual authorized role]** to **[one concrete recurring decision or action]**. Hero metric: **[the role's most useful verified metric]**, visually dominant at the **top-right in RTL**, with trend and **a meaningful previous-period or target comparison**. Supporting KPIs: **two or three**, each with clear comparison, favorable/unfavorable interpretation and accessible icons/text in addition to color. Charts: a clean **line chart** for **[actual temporal measure, 12 weeks or configurable months]**; a **horizontal bar chart** for **[actual categories]**. **Never use pie charts, 3D charts or visualized invented data.** Table: **[prioritized actionable records]** with localized statuses and relevant columns, filter, pagination and row details. Layout: existing RTL sidebar where appropriate; KPI row above a **two-thirds-width trend chart** beside a **one-third category breakdown**, actionable table below. Neutral background, one main accent from authentic institutional brand, semantic status palette only when needed. Typeface: **Tajawal** or **IBM Plex Sans Arabic** if actually licensed/available, with safe fallback; not a careless default font. Include loading/skeleton, empty, filtered-empty, partial data, error, unauthorized and offline/interrupted states.

The hero KPI is **at top-right**, not top-left, because app language is Arabic RTL. Comparisons must use comparable periods, indicate direction and distinction between "higher is better" vs "higher is worse" (e.g., overdue requests). Do not show trend if historical data or targets do not exist; show a neutral, comprehensible unavailable state instead. Distinguish unique people, requests, households and transactions to avoid double counting. Every KPI and chart must have a backend definition documented and access-scoped query.

### Visual system

- Light theme first, quiet neutral off-white background, white content surfaces, restrained real brand blue/green accents. Color should communicate actions/status, not overwhelm. Dark mode only if existing app already supports it or can be added safely after priority screens.
- Professional typography with clear numeric hierarchy and human-readable Arabic numbers/formatting as appropriate, consistent sizes and line heights, polished alignment and logical RTL spacing. Ensure legal names and IDs are never silently truncated beyond recognition.
- A consistent spacing and radius scale, accessible focus/hover states, clear table density options, semantic neutral/success/warning/error statuses; contrast-compliant and never color-only indications.
- Reuse installed iconography (Lucide/Bootstrap icons/etc discovered), coherent stroke size. No emoji as UI icons. Restrained subtle motion with reduced-motion support. Avoid giant decorative illustrations, glossy/3D widgets, gradients everywhere, gratuitous animations or excessive KPI cards.
- Responsive 1440/1280 desktop, 1024 tablet, 768 and 390/360 phone. The staff sidebar collapses appropriately; portal uses a simpler mobile-friendly header/nav. Tables become sensible cards or horizontally scrollable tables with sticky actions when needed; no clipped actions.
- Optional use of CSS grid/flex and existing styling conventions; no new UI framework without strong evidence. Use one reusable token/component system rather than hundreds of local styles.

## 3. Role × decision × hero/KPIs/charts/table specifications

**Never infer that every role exists in code.** Implement for discovered roles, mapping design patterns below to actual policies and modules. Where actual data source is missing, show "غير متاح" and record work needed; do NOT invent figures or unauthorized backend logic.

### 3.1 Executive Director — المدير التنفيذي

**Decision:** Where is executive intervention/approval needed, and which institute goals are at risk?
- **Hero:** approvals requiring the executive director's decision **now** (clearly segmented by type and overdue); comparison vs prior week.
- **Supporting KPIs:** overdue executive decisions; active institute programs/campaigns needing attention; operational exceptions or completion against measured plan (only from real system sources).
- **Line:** weekly incoming vs finalized executive approval requests, last 12 weeks.
- **Bars:** urgent pending approvals by department or process, categorized based on actual department tree.
- **Table:** «قرارات تنتظر اعتمادي» with reference, type, department, submitter, submission date, age/SLA, status and actions to review; do not provide one-click blind approval for sensitive campaigns.
- **Campaign list panel:** initial lists and modification requests must show before/after versions, quota/inventory effects, reason, traceable approval and irreversible audit. Only executive can approve; show locked status.

### 3.2 Orphans & Sponsorships Department Head — رئيس قسم شؤون الأيتام والكفالات

**Decision:** Which guardian/orphan applications, work assignments and sponsorship or aid cases need staff action?
- **Hero:** actual unique registered orphan count, with the provisional vs final breakdown and historical delta if the comparison exists.
- **Supporting:** new/pending guardian requests, currently sponsored unique orphans, incomplete or overdue case files; prioritize 2–3 on desktop and adaptable summary elsewhere.
- **Line:** newly registered unique orphans and finalized reviews per week, last 12 weeks, with proper separate series.
- **Bars:** active unique orphan counts by governorate; drill down to city/area, honoring dynamic `locations` and record-level visibility.
- **Table:** «يحتاج إلى إجراء» with request/case ID, case type, beneficiary identity in masked/minimal form, assigned employee, age, priority, status, next action.
- **Additional compact widget:** staff workload distribution/overdue assignments (no dubious employee performance rankings). Optional age distribution linked to filtered results.
- **Guardian/orphan registry:** table or compact cards user toggle, contextual right-side details/drawer, preserve filters/back navigation, exact national-ID searches including leading zero, four-name search fields for child and guardian, dynamic geography. No fake production stats.

### 3.3 Orphan Affairs registration employee — موظف التسجيل والمراجعة

**Decision:** What case should I process next without missing documentation or duplicating records?
- **Hero:** my assigned cases requiring action today.
- **Supporting:** pending completion requests, cases awaiting guardian proof, overdue tasks.
- **Line:** my weekly completed reviews vs assigned reviews, last 12 weeks (if meaningful).
- **Bars:** my cases by actionable workflow stage.
- **Table:** request number, child/family, guardian, missing key items, assignment date, due date, status, «فتح للمراجعة».
- Provide quick actions «إضافة وصي»، «تسجيل أسرة»، «إضافة يتيم»، «الاستيراد الذكي» only if permitted.

### 3.4 Social Worker — الباحث الاجتماعي

**Decision:** Which family visits and assessments should I prioritize?
- **Hero:** overdue assigned visits/assessments.
- **Supporting:** today’s visits, pending follow-up, assessments requiring verification.
- **Line:** completed vs scheduled visits over 12 weeks.
- **Bars:** assigned cases by geographic area or follow-up category.
- **Table:** family reference, zone, visit date, follow-up need, sensitivity-safe status, action. Never expose private diagnostic detail in dashboards.

### 3.5 Sponsorship Officer — مسؤول الكفالات

**Decision:** Which sponsorship commitments and orphan coverage gaps need follow-up?
- **Hero:** active sponsored unique orphan count or uncovered urgent cases, select according to actual role’s task.
- **Supporting:** pending sponsorship renewals, commitments needing follow-up, suspended/expired sponsorships.
- **Line:** verified active sponsorships or monthly coverage trend (use true operational records).
- **Bars:** coverage by governorate or sponsorship program.
- **Table:** sponsorship reference, child, sponsor identity as permitted, start/end, actual status, upcoming action. Separate pledge, accounting receipt, and actual disbursement.

### 3.6 Relief Campaign Operator — مسؤول الحملات والتوزيع

**Decision:** Can the approved campaign be executed accurately and on schedule?
- **Hero:** outstanding **approved** allocations awaiting confirmed fulfillment (not unapproved nominations).
- **Supporting:** stock reserved/available, confirmed delivered units, unresolved reconciliation/duplicate flags.
- **Line:** daily actual delivered units vs planned units, period set by campaign.
- **Bars:** approved vs delivered quantities by governorate / item size where grounded.
- **Table:** campaign/item/area, approved-list version, reservation, quantity, delivery status, exception/next action.
- Campaign setup has general/weighted/hybrid nomination, hybrid default, explainable criteria and scoring snapshots, primary and reserve lists, geospatial quotas, size/quantity constraints and cross-campaign duplication alerts. A proposed sheet import does NOT create an approved list. Lock approved versions; only executive director can approve initial and amended lists. Benefits only count after validated delivery.

### 3.7 Human Resources Head — رئيس الموارد البشرية

**Decision:** Which staffing, attendance and leave issues need management action?
- **Hero:** actionable pending leave/HR approvals or attendance exceptions according to existing processes.
- **Supporting:** active staff count, overdue HR tasks, absence/coverage exceptions.
- **Line:** actual attendance/absence trends by week or month.
- **Bars:** staff distribution by department or leave category (with legitimate underlying data).
- **Table:** employee, request/exception type, date, status, reviewer, next action. Protect employee personal information.

### 3.8 Accounting/Finance Head — رئيس قسم الحسابات

**Decision:** Which approved financial workflows require reconciliation or authorization?
- **Hero:** pending legitimate financial approvals/settlement exceptions in real finance module.
- **Supporting:** reconciled transaction value, unprocessed approved payments, variance vs approved budget if available.
- **Line:** verified monthly receipts and expenditures where authorized.
- **Bars:** expenditures by authorized program/department.
- **Table:** reference, counterparty masked appropriately, amount/currency, type, due date, verified workflow status, link to original ledger. Do not create financial ledgers or infer money movement from proposed aid.

### 3.9 Assets, Buildings & Rentals — مسؤول/رئيس الأصول والمباني والإيجارات

**Decision:** What asset maintenance, rent deadlines and property risks require action?
- **Hero:** overdue maintenance or contracts requiring renewal, grounded in actual module.
- **Supporting:** active assets/contracts, upcoming expiration, unresolved asset incidents.
- **Line:** completed vs opened maintenance work/orders or monthly rental obligations, based on actual data.
- **Bars:** assets or contracts by status/location/type.
- **Table:** asset/property, location, owner/assignee, deadline, status, next action.

### 3.10 Public Relations / Communications — العلاقات العامة والإعلام

**Decision:** Which authorized communications, campaigns or approvals are due?
- **Hero:** assigned outreach/campaign tasks awaiting action.
- **Supporting:** scheduled communications, pending reviews, completed deliverables.
- **Line:** actual publication or engagement metrics only if legitimate sources exist.
- **Bars:** deliverables by campaign/channel. Avoid fabricated social platform analytics.
- **Table:** task/campaign, channel, owner, due date, status. PR may operate ONLY assigned relief campaigns, not obtain blanket orphan-file access.

### 3.11 General employee / administrator — الموظف العام / مدير النظام

**Decision:** What can I complete today, and are there technical issues needing attention?
- Employee: hero «مهامي اليوم», action-centric table, recent authorized files and notifications; avoid executive analytics.
- System administrator: hero actual technical failed integrations/jobs or actionable requests, supporting application/system health only where observable; status charts and audit exception table. **Technical administrative privileges do not grant executive director approval rights**.

### 3.12 Beneficiary/Guardian Portal — portal.al-amal.ps

**Decision:** What do I need to do to register, complete or follow up on my own family requests?
- **Hero:** current family registration/application stage and its required next action; not a fabricated numerical KPI.
- **Supporting cards:** documents requested, applications requiring completion, confirmed aid updates; minimal counts only when legitimate.
- **Charts:** do not force line/bar analytics on a family with no meaningful history; use a clear request-status timeline and family child cards instead. The universal dashboard grammar is purpose-driven, not compulsory decoration.
- **Table/cards:** my requests and confirmed benefits, request number, type, submission date, public-safe status, next action.
- Five portal primary areas: «الرئيسية»، «أسرتي»، «طلباتي»، «مساعداتي وكفالاتي»، «الإشعارات». Separate account settings. Fast mobile-first screens, server-side draft saving, uploads with retry.
- Family onboarding supports multi-orphan draft and detailed father/mother/guardian/family/address/education/health sections; display strictly permitted fields, secure private attachments. Allow registration of new guardians without a prior file, but **no view of official child profiles until child-specific verified guardianship approval**. Keep parent identity distinct from legal guardian and household membership.

### 3.13 Other existing departments/roles

For EVERY real department not explicitly covered above: inventory its actual workflow and identify one primary operational decision, 1 hero measure, 2–3 supporting measures, one accurate line trend, one horizontal categorical bar (when supported), and a prioritized actionable table. Reuse and tailor shared components. If time-series is unavailable, do not fabricate values: substitute an action list with clear explanation. Cover subordinate employees and managers separately wherever permissions and workflows materially differ.

## 4. End-to-end interface modernization beyond dashboards

Apply consistent patterns to all existing screens, not just dashboard landing pages:

1. **App shell and navigation:** persistent desktop RTL navigation, section grouping, searchable links if many, active states, breadcrumbs, compact responsive navigation, accessible keyboard navigation, alerts, profile menu, permission-aware links. Keep menu density manageable; preserve six Orphan Affairs major entries.
2. **Lists and registries:** clear search, reusable advanced filters, active filter chips, saved filter state on detail return, sortable accessible tables, compact cards where useful, visible record counts, paginated server-side results, safe export with current filters and RBAC, bulk actions only when lawful.
3. **Record preview and detail:** contextual slide-out preview on desktop / mobile drawer; direct link to full record; record identity and status, related guardians/children, audit trail, tabbed domains, quick actions based on permissions. Avoid excessive nested modals.
4. **Complex registration:** Arabic RTL multi-step wizard for family/orphan/guardian/parents, reusable accessible field components, auto-save drafts, conditional validation, per-child repeatable panels, review before submit, optimistic experience with correct server validation, attachment retry and clear mandatory vs optional indicators.
5. **Smart Import:** prominent «الاستيراد الذكي» shortcut/icon in dashboards, Orphan Registry and contextual lists; XLSX/CSV mapping, preview, data quality warnings, family grouping, source-to-record merge proposals, duplicate detection, row-level errors, provisional approval only for eligible verified-identity/no-critical-conflict records, transparent batch progress and safe resume; never bypass verified guardianship or executive approval. Show actual created/updated/skipped counts from DB, not guessed status.
6. **Reviews and approvals:** side-by-side old/new field diffs, evidence checklist, comments, decision reasons, status timelines, director-only initial campaign roster approvals and amendment decisions, locked version indicator, history and rejection reasons. Never grant permission via visible controls alone.
7. **Campaigns / distributions:** wizard with stepper, explainable rule version, criteria weights, impact preview, main/reserve rosters, quotas/garment sizes, approval history, stock allocation and fulfillment reconciliation, idempotent offline handover statuses. Never overstate availability or mark nomination as delivery.
8. **Reports:** responsive filter builder, measure definitions and timestamps, charts only for actual values, accessible downloadable reports, donor-specific protected output; link charts to corresponding scoped filtered records.
9. **Notifications / task inbox:** severity, age, next action and smart grouping; actionable state only, avoid alarm fatigue, respect privacy.
10. **Attachments:** private storage, preview via authenticated authorization, file metadata, validation/scan hooks if present, upload retry, no leaking raw URLs or medical content.
11. **Microcopy:** concise Arabic instructions, explicit corrective inline error messages, helpful success notifications, undo only for legitimately reversible actions, confirmation for destructive changes, differentiated statuses «مسودة», «بانتظار المراجعة», «معتمد مبدئيًا», «معتمد نهائيًا», etc.

## 5. Data visualization specification

- Only actual database-driven metrics and role-scoped queries; document each KPI calculation, time window and unique record denominator. Exclude duplicates of children across households and aid across household members.
- **Hero** large at top-right, concise label, comparison vs prior comparable period or target, scope/time and click-through. When no comparison is available show "لا تتوفر بيانات مقارنة" rather than fabricated arrows.
- **Line chart** 12 weeks by default, real time bucket labels, multi-series where meaningful, correct units, clear tooltip, accessible data-table alternative. Zero is not missing.
- **Horizontal bar chart** for area/department/status distributions; RTL readable labels, no 3D or pie. Color only for meaningful categories, not decoration.
- **Table** below with status badges, date, assignee, next action; semantic action labels, pagination/filter, loading/empty/error, sorting and drilldown.
- Support screen readers, chart descriptions, textual summaries and exact values via accessible table when visually charted.
- Be careful with sensitive counts in small groups; avoid re-identification for public/beneficiary users.

## 6. Universal component design contracts

Create/adapt reusable components in the actual stack: `AppShell`, `PageHeader`, `MetricCard`, `TrendChart`, `CategoryBarChart`, `ActionTable`, `StatusBadge`, `FilterPanel`, `RecordDrawer`, `StepWizard`, `FormField`, `FileUploader`, `EmptyState`, `LoadingSkeleton`, `ErrorState`, `PermissionDenied`, `ConfirmationDialog`, `AuditTimeline`, `ApprovalDiff`, `Toast`, `SmartImportAction`. Names are illustrative, align with repository naming.

For each component, ensure: Arabic RTL, correct labels and accessible controls, responsive layout, focus rings, keyboard operability, disabled states, real loading/error flows, permission handling, no external unsafe data leakage. Use non-destructive progressive enhancement. Avoid global CSS changes that break public site or portal styling.

## 7. State and edge-case matrix (required per upgraded screen)

- Initial loading: compact skeletons with sensible layout stability; do not flash zero as actual data.
- Empty: concise Arabic explanation and a scoped next action if permitted.
- Filtered empty: "لا توجد نتائج مطابقة للفلاتر" + clear filters.
- No historical data: neutral trend unavailable, no fake chart.
- Partial data: note incompleteness and link to correct record.
- Slow connection: progress and retry with idempotent operations; don't duplicate submissions.
- Validation: field-specific Arabic errors, preserve draft data.
- Permission denied: safe 403 and non-leaking copy; don't render sensitive fetches.
- Server errors: actionable error notice and retry without losing user input.
- Long labels, Arabic names, masked IDs, tiny screens, large data sets, exports and repeated keyboard interactions.
- Provisional vs final: visually distinct and understandable, not interchangeable.

## 8. Security, performance and code-quality rules

- Enforce policies and per-resource access on the server. No frontend-only protection. Revalidate authorization for dashboard metrics, row detail, exports, child profiles, attachments and bulk actions.
- No access to unverified child relationships in portal. Mask or omit sensitive orphan/health/economic information in lists and logs according to actual permissions.
- Preserve existing backend and data contracts unless a verified defect requires a minimal compatible change with tests.
- Use eager loading, proper pagination, query limits, database indexes when justified, avoidance of N+1, aggregated queries, cached metrics only with explicit invalidation and security-aware keys. Do not cache cross-tenant/role-sensitive responses indiscriminately.
- Keep asset weight modest for Gaza connectivity: lazy-load charts/heavy pages, prefer existing libraries, compress CSS/JS, optimize images, preserve quick first rendering.
- No dummy production data, no breaking route changes without migration/redirect plan, no `migrate:fresh`, no `db:wipe`, no truncation or deletion of operational data.
- Preserve app integrity and baseline features: orphan registration, guardianship, executive approvals, import, campaigns, field delivery, finance, HR, assets and beneficiary portal.

## 9. Implementation phases — execute, don't only describe

**Phase A — Discover and document:** audit screens, roles, current components, routes, permissions, data definitions, layout and responsive weaknesses; write inventory and plan.

**Phase B — Design foundation:** apply institutional design tokens, Arabic type stack, spacing, icons, shell, navigation, shared states and components; ensure baseline screenshots still work.

**Phase C — Highest-impact dashboards:** executive director, Orphan Affairs head, orphan employee, social researcher, sponsorship/campaign officers; all role-scoped and actual data. Test accurate counts and locked approvals.

**Phase D — Remaining departments:** HR, accounting, assets/buildings/rentals, PR, general staff, system admin and any other actual department. Keep department-specific meaningful metrics.

**Phase E — Record and workflow pages:** orphan/guardian/family registration, registry, review, approvals, import, campaigns, delivery, finance/HR and report interfaces. Preserve module-specific semantics.

**Phase F — Beneficiary Portal:** modern mobile-first RTL design and five-area navigation, accessible family onboarding, safe request tracking and authorized benefit history; ensure no unverified orphan access.

**Phase G — QA / polish:** responsiveness, accessibility, keyboard navigation, RTL charts, contrast, loading/empty/error cases, real integration tests, role restrictions, performance, regression, screenshots. Fix defects and document outcomes.

After EACH phase, provide succinct report listing files touched, screenshots where possible, commands/tests actually run, exact pass/fail outcomes and risks. Do not stop after wireframes. If blocked by environment, make the highest-value implementable changes and state what could not be verified.

## 10. Acceptance tests / Definition of Done

1. Every existing AIMS role sees an appropriate functional dashboard, not one cloned generic dashboard.
2. Each role's hero KPI is the primary operational measure and reflects correct server-side authorized data; charts and tables drill down correctly.
3. The application remains fully Arabic RTL with no clipped labels at common mobile/tablet/desktop widths.
4. The executive director can approve initial campaign rosters and roster modifications; other roles cannot, including through direct API requests. Approved versions remain locked and auditable.
5. Orphans/guardians/family screens retain detailed registration, per-child guardianship and provisional status semantics.
6. Smart Import provides a clear action/icon within existing six Orphan Affairs areas, and results honestly distinguish provisional operational records from verified ones.
7. The beneficiary portal offers only the account's own requests and approved relationships, with server-side IDOR checks and private attachments.
8. Charts use real data, line plus horizontal bar where appropriate, no pie or 3D, and no invented trend. Absent data shows a reasoned empty state.
9. Loading, filtered empty, error, interrupted, denied and partial-data states are implemented and tested.
10. Tables support contextual filters, pagination, accessible badges and accurate export permissions.
11. Existing core workflows, links, forms and database data remain functional after redesign.
12. Tests for permissions, queries, forms and views are executed; failings are not hidden. Report remaining work explicitly.

## 11. Required deliverables

- `docs/UI_UX_SCREEN_INVENTORY.md`
- `docs/UI_UX_IMPLEMENTATION_PLAN.md`
- `docs/UI_UX_DESIGN_SYSTEM.md` (actual tokens/components and their usage)
- Updated real Laravel/frontend views/components/styles/services only as needed.
- `docs/UI_UX_ROLE_DASHBOARD_SPECS.md`: for each real role, decision, hero/KPIs, formulas/sources, charts, table, empty/loading/error states.
- `docs/UI_UX_VERIFICATION_REPORT.md`: routes/screens checked, viewport sizes, access tests, functionality checks, build/tests and honest results.
- List of changed files, migration changes if absolutely necessary, deployment/build instructions and known limitations.

**BEGIN NOW:** inspect the repository rather than assuming it, produce the inventory/plan, and then implement phase by phase in working code. Keep all changes aligned with the approved AIMS business rules and real existing modules. Do not ask for aesthetic approvals if repository branding and prior directions resolve the issue. Never claim completion without implementation and verification.
