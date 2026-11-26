# AIMS 2.0 — Final Implementation Prompt for Claude Opus

**Project:** Al-Amal Institute for Orphans — Orphans and Sponsorships Department (قسم شؤون الأيتام والكفالات)  
**Deliverable:** An integrated, operational Laravel/PostgreSQL module with Arabic RTL user interfaces.  
**Status:** Final agreed functional baseline, expanded with mandatory detailed child, guardian, deceased father, and mother records. This document is the single source of implementation instructions; do not combine it with outdated prompts that contradict it.

## ROLE AND MISSION

You are the lead Laravel/PostgreSQL software architect, domain engineer, UX designer, and full-stack developer working **inside the existing AIMS repository**. Inspect the actual application, schema, current authorization engine, Vue/frontend setup, shared accounting, stores, audit, and field-delivery integrations before changing anything. Implement a redesigned, coherent **Orphans and Sponsorships Department** with accurate workflows and a low-friction staff experience. This is an implementation assignment, not a proposal for isolated mock screens.

**Non-negotiable:** All user-facing content, menus, labels, input validation, report titles, notification messages, and statuses must be in **Arabic with correct RTL**; use established official Arabic terminology. English may be used in source identifiers, test names, and technical documentation.

## 1. REBUILD SCOPE AND SAFETY

The user explicitly authorized removal of the previous department-exclusive implementation and its disposable **old staged import records**, even without creating backups for that disposable dataset. Re-import will be performed later. However, this is NOT permission to damage shared AIMS components or external accounting, confirmed financial transactions, actual delivery records, shared audit history, institute organization hierarchy, portal infrastructure, or any other department. Before deletion, enumerate real dependencies, foreign keys, and shared consumers. No indiscriminate `migrate:fresh`, `db:wipe`, global `TRUNCATE ... CASCADE`, destructive changes to shared tables, or unbounded recursive delete. Reuse shared services and existing tables where compatible; remove department-exclusive obsolete code and data, not unrelated functions. Document exactly what is changed/deleted. Database changes must be reviewed for dependencies and executed transactionally where possible. Verify the production environment and migration scope before running operations.

Do not assume the database is MySQL, or that the frontend is exclusively Blade/Bootstrap: the existing AIMS architecture uses Laravel, PostgreSQL, and Vue screens in places. Inspect and follow the actual project conventions. The public `al-amal.ps` website is a separate Laravel project and is out of scope. The internal employee portal is `system.al-amal.ps`; the beneficiary portal is `portal.al-amal.ps` and must integrate securely through the existing modular AIMS application. Do not rebuild the portal as a separate database/source of truth.

## 2. INFORMATION ARCHITECTURE: SIX MAIN ENTRIES ONLY

The department sidebar has only:

1. **«الرئيسية»** — personalized manager/staff dashboard.
2. **«إدارة الأيتام والأوصياء»** — unified registry, direct guardianship relationships, edits, full profiles.
3. **«الطلبات والمراجعات»** — beneficiary portal applications, checklists, appeals, follow-up.
4. **«الكفالات والمساعدات»** — sponsors, sponsorships, commitments, assistance tracking.
5. **«الحملات الإغاثية»** — campaigns, targeting, scoring, approvals, lists, delivery, closure.
6. **«التقارير والإحصائيات»** — filtered analytics, exports, performance, donor reports.

«إعدادات القسم» is accessible only to appropriately authorized managers/admins through the existing settings area, NOT as an extra department sidebar item. Keep contextual subviews inside the six spaces, avoiding redundant tabs and icons. An important goal is fewer clicks and faster decision-making, not maximizing page count.

### Shared UI design system

Use a restrained blue/teal institute palette, readable Arabic typography, accessible contrast, clear table density, meaningful status badges with explicit text, responsive layout, consistent feedback and loading/empty states. One primary action per card/row; secondary actions in «المزيد». Progressive disclosure of advanced filters. No fake production KPIs or sample orphan records. Global search remains available, without duplicating filter panels on every screen.

## 3. FINAL DASHBOARD DESIGN — FIVE DECISIONS NOW SETTLED

### A. Role-adaptive landing page

Use **one underlying dashboard** that renders different content based on actual department roles and assignments, not separate backend systems.

**Department head «رئيس القسم» — administrative/oversight view:**
- Three prominent KPI cards: **«الأيتام المسجلون»** (display total and *separately* complete/approved counts), **«طلبات بوابة المستفيدين»** (new, awaiting review, completion requested), and **«الأيتام المكفولون»** (active sponsorship coverage; define the counting rule precisely).
- Two useful and filterable graphs: **«التوزيع الجغرافي»** by governorate/city and **«التوزيع حسب الفئات العمرية»**, showing unknown/unclassified values explicitly. Source categories/age bands come from configurable «ثوابت النظام».
- ONE consolidated **«يحتاج إلى إجراء»** queue: portal reviews, guardian conflicts, incomplete records, appeals, delayed sponsorship activities, campaign decisions, and related approvals. The CEO-only campaign list approvals are displayed as actionable items **for the executive director**, and as read-only tracking items to other users where authorized.
- **«توزيع العمل ومتابعة الموظفين»**: active assignments by staff member, completed vs pending vs overdue, workload, average processing time; click employee to open filtered assignment list. Do not use misleading productivity rankings without context.

**Employee — operational view:**
- **«مهامي اليوم»** (assigned applications, orphan/guardian files, field visits, follow-ups, due dates), sorted by priority and overdue state.
- Compact quick actions: **«إضافة وصي»**, **«إضافة يتيم»**, **«مراجعة طلب»**, **«البحث عن ملف»**. Show only authorized actions.
- Recently handled records and deadlines; no unnecessary oversight graphs.

**Social researcher:** assigned cases, assessments, scheduled/overdue visits; **sponsorship/campaign specialist:** sponsorship and campaign work queues, nominations, distribution states. Same underlying interface architecture, scoped appropriately.

### B. Alert prioritization

Use one task/alert feed, not separate duplicate tables. Order actionable items primarily by overdue/critical due date, then configured business urgency, then submission time, without unsupported automated determinations of vulnerability. Each task shows subject, source, assignee, due date, status, and a context-preserving action link. The department head can assign/reassign tasks; ordinary staff see only their assignments unless authorized otherwise.

### C. KPI definitions

Document query definitions for each KPI and whether it counts children, guardians, applications, sponsorships, households, or delivery units. Staged import records must never silently inflate approved registry counts. Guard against join fan-out and double counting. Sponsorship commitments are not confirmed receipts and approval is not delivery. Show unavailable data as unavailable, not zero if unknown.

### D. Chart drill-down

Clicking a geographic region or age band opens «إدارة الأيتام والأوصياء» with matching filters, with URL-state retention, server pagination, and the same number of records as the graph slice where counts are comparable. All navigation supports back/forward and retains search/filter/sort state.

### E. Quick action design

Avoid 10+ floating icons or separate sidebars. Keep a small set of contextual actions on the dashboard; use one «إضافة جديد» dropdown in registry and one «تصدير» dropdown in result/report views.

## 4. DOMAIN MODEL: ORPHAN, LEGAL GUARDIAN, HOUSEHOLD

**The orphan is the authoritative individual profile. Every operational orphan record MUST be linked to at least one registered legal-guardian candidate/relationship.** There is no save of an operational orphan, including draft, without a linked guardian record. Staff may create guardian first or inline within the orphan creation flow. Ensure atomic persistence; do not create a child without its relationship if the transaction fails. A guardian may exist before any linked children.

**Critical distinction:** linked/claimed guardianship is NOT verified legal guardianship. Each child–guardian relationship has its own claim evidence, status (`claimed`, pending, approved, ended, rejected as appropriate), effective dates, review decision, and history. Authorization to view a child through the beneficiary portal requires individually approved guardianship, not merely the child's household, source spreadsheet guardian name, family relation, or successful guardian-account activation.

**«الأسرة» / household** is optional social/economic/residential context for assessment, household-based aid, and field visits. It NEVER automatically establishes legal guardianship. One guardian may be linked to orphans in multiple households. Children in one household may have different guardians. Never merge households merely because guardian IDs match.

A guardian replacement workflow must record the prior relationship, reason, supporting evidence, approval and start date, and activate the new relationship in a coherent transaction, preserving history and revoking old portal access when appropriate. When legally valid access expires before a replacement is approved, the system must restrict access while escalating for urgent review, rather than falsely treating an expired guardianship as valid.

Keep **independent states** for: orphan workflow status, profile completeness, each guardianship verification, guardian portal account, sponsorship coverage, application review, nomination, and actual delivery. Labels/configurable value sets belong to «ثوابت النظام» where suitable, but backend transition invariants remain enforceable.

## 5. «إدارة الأيتام والأوصياء» — UNIFIED REGISTRY

Use **two primary views**: «الأوصياء» and «الأيتام». Results can switch between **compact cards and table**. Selecting a result opens a **side preview** (slide-over on smaller screens) without navigating away; full profile opens on explicit action. Preserve URL-bound query, selected filters, sort, pagination, and back navigation.

**Search:** one always-visible quick search for guardian/orphan full name, partial Arabic name, internal reference, **exact «رقم هوية اليتيم» or «رقم هوية الوصي»**; support Arabic and Western numerals, leading zeros and exact equality for ID. Provide an expandable advanced four-part name form for either entity: «الاسم الأول», «اسم الأب», «اسم الجد», «اسم العائلة». Do not guess unavailable name parts or rewrite originals; index/search normalization is read-only. Use appropriate sensitive-ID access and limit exposure; do not inadvertently disclose unrelated child identities through search or exports.

**Dynamic filters:** governorate, city, area/neighborhood, original address, displacement address, age/age band, sex, orphanhood type, completeness/approval status, guardianship status, sponsorship status, educational/social state, last benefit date, existing campaign nomination, and issue type, when supported by real data. Geographic and categorical pickers reflect «ثوابت النظام», not hardcoded enumerations. Keep unknown values visible.

**Guardian preview** shows full four-part name, appropriately masked national ID, photo if genuinely available, contacts, original home and displacement address, status, notes, a directly linked orphan list with *separate relationship statuses*, and a small summary of relevant orphan/household benefit history without double-counting family-level aid. Full guardian file contains documents, applications, changes, and linked orphan access controls.

**Orphan full profile** is organized into a maximum of four coherent areas: «البيانات الأساسية», «الوصي والارتباطات», «التقييم والمتابعة», «الكفالات وسجل الاستفادة». Integrate documents and history contextually instead of multiplying tabs. Support authorized editing, field-level integrity, audit trail, and duplicate warnings.

Internal staff can create/edit both entities. Before creation, detect exact identity duplicates and possible Arabic-name collisions; show a non-destructive compare/review, never auto-merge on fuzzy name similarity. Preserve source identity formatting and distinguish 9-digit format checks from authoritative civil-registry validation (e.g., `000000000` must be flagged, not treated as verified).

## 6. «الطلبات والمراجعات» — GUARDIAN PORTAL WORKSPACE

Incoming requests from `portal.al-amal.ps` appear in one searchable, assignable queue. Review in a single workspace: submitted guardian identity vs existing record, supporting files, claimed children, duplicate/conflict indicators, and a short configurable checklist. Provide **«اعتماد الحساب»**, **«اعتماد الوصاية» per child**, **«طلب استكمال»**, **«رفض مع سبب»** as distinctly authorized workflow actions, with proper back-end checks.

Approve account and every child's guardianship independently. Approving account does NOT unlock unverified child files. Staff can request missing documents with one editable notification and track resubmission without overwriting approved data. Preserve reviewer, date, comparison, and decision history. Department head can assign/reassign work.

Enable **appeals / «الاعتراضات»** for eligibility or omission from a campaign. The appeal is linked to a beneficiary and campaign, can be reviewed with evidence, and does NOT edit an approved campaign list without the executive director's explicit approval.

## 7. «الكفالات والمساعدات» AND BENEFIT HISTORY

Support individual/institutional sponsors, sponsorships for one/multiple children, eligible whole-household or pooled programs where configured, active/partial/expired/suspended commitments, renewals and coverage; avoid assuming 1 sponsor = 1 orphan. Financial receipts/disbursements are recorded/confirmed through **existing Accounting**, NOT a parallel finance ledger. Expose linked payment/expense status appropriately.

The beneficiary **«سجل الاستفادة»** is a first-class timeline inside the orphan file, with references to program/campaign, type, amount/quantity, beneficiary and actual recipient, date, fulfillment reference, and corrections. Keep nomination, approval, reservation, and actual fulfillment separate. Only **confirmed execution/delivery** counts as completed benefit, without double-counting household assistance per orphan. A guardian summary shows benefits associated with linked orphans and household assistance without reassigning historical benefit ownership when guardianship changes.

## 8. «الحملات الإغاثية» — THREE-MODE NOMINATION ENGINE

The department manages campaigns (not a separate organizational department). The public relations officer may be assigned limited access to individual campaigns without gaining general orphan-file access.

**Supported beneficiary units per campaign:** individual orphan, household, guardian, or individual case (supported by a validated beneficiary reference; never ambiguous polymorphic IDs). Examples: clothing per child, food parcel per eligible household. Track **beneficiary, claimant/guardian, actual recipient, and distribution unit** separately.

**Nomination modes:**
1. **«عام»**: eligibility filters only. When eligible demand exceeds inventory, do not silently choose a subset; require an approved prioritization/tie-break policy.
2. **«بالنقاط»**: weighted ranking of eligible beneficiaries with transparent score contributions.
3. **«مختلط» (default)**: mandatory eligibility gates first, then weighted ranking where capacity is scarce.

**Dynamic configurable criteria:** orphanhood type, dependent ratio and household needs, economic situation of father before death as contextual historical data, current guardian/household economic condition, displacement, housing vulnerability, unresolved needs, previous relevant receipt, and campaign-specific requirements. Do NOT infer missing economic information is prosperity; flag missing information for review according to a documented policy. Do NOT automatically exclude a child simply for earlier aid of a different type. The **general social assessment** remains independent of the **campaign-specific priority score**; weights and scales are configured, validated and versioned. Track the data snapshot, source, effective rule version, and score explanation for every nomination run.

**Capacity constraints:** campaign quantity, optional geographic quota, size/variant/SKU, warehouse stock reservation, allocation, and reserve/waitlist. Inventory operations MUST integrate with existing Stores; do not duplicate stock accounting. Support no-allocation without available compliant quantity and transparent quota redistribution with permission.

**Simulation before commitment:** show eligible candidates, excluded/pending verification, actual resource capacity, count by region and age, score distribution, conflicts and missing evidence, previous similar campaign allocations/deliveries, and impact of changing rules. Simulation does not approve a list or reserve stock. Provide primary and reserve lists with explanatory scores and reasons; allow human review.

**Cross-campaign duplicate alerts:** detect potentially overlapping same-kind assistance in relevant date windows/active reservations, alert reviewers, and provide exceptions with reasons; no automatic exclusion from a mere match.

### EXCLUSIVE EXECUTIVE DIRECTOR APPROVAL — HARD RULE

**ONLY «المدير التنفيذي» can approve the INITIAL finalized campaign beneficiary list and ANY change to an already approved list.** This is a hard workflow authorization invariant, NOT just a hidden UI button. The department head, administrator, staff, or technical system manager cannot independently approve it by virtue of their other roles. Reuse the existing executive director role identity and approval engine where available; do not create a competing authorization system.

- Staff prepare and review draft lists. Department head may recommend/refer them.
- The executive director approves or rejects the initial list with a recorded decision.
- Once approved, list/version is immutable. Any add/remove/replace beneficiary, quantity/size change affecting allocation, quota transfer, or rerun impacting approved membership requires a **specific change request**, clear diff, justification, and **new explicit executive director approval** before publishing a new version.
- Approval binds to a particular list version and exact proposed changes, not an open-ended permission to edit.
- Preserve all prior versions, requester/reviewer/approver identities, timestamps, reasons, and audit trail. If delivery has occurred, its history is immutable; corrections are separate events and ledger/warehouse reconciliations.
- No user may silently mutate an approved list through API, SQL-facing application paths, import, batch jobs, sync, or UI. Apply concurrency control/version checks and reject stale edits. The underlying DB permissions/data-integrity strategy should support this, while acknowledging a DB superuser can technically bypass application controls.

### Campaign workflow

Create campaign -> specify aid unit, donor, resources, target locations -> configure eligibility gates + rule mode/weights + stock/quotas -> run simulation and review candidate reasons -> produce primary/reserve lists -> department head review/referral -> **executive director approval** -> lock list -> Stores reserve/issue materials -> field delivery -> reconcile -> close campaign and issue donor report. Handle appeals through reviews; requests that impact approved lists must return to the executive director.

### Offline delivery

Preserve/integrate existing field-device offline delivery capability (Electron if currently available), independent employee assignments and authorized proxy recipient checks. Each delivery uses a stable idempotency identifier and server-side uniqueness/transaction handling; repeated reconnect/sync does not create extra delivery, stock movement, or completed-benefit entry. Confirmed deliveries can only be corrected through authorized reversible events, not silent deletion. Keep a clear distinction between prepared, issued, delivered, undelivered, returned and reconciled.

## 9. CAMPAIGN UI — FOUR-STEP WIZARD, SIMPLE LIST REVIEW

**Four-step campaign creation:**
1. **«الأساسيات»**: title, donor/funder, dates, aid type, beneficiary unit, quantities/SKUs/sizes, source warehouse.
2. **«الاستهداف»**: governorates/cities, age bands, eligibility gates, similar previous aid policies, quotas.
3. **«معايير الترشيح»**: general/scored/hybrid, rule weights (sum and allowed missing-data treatment), saved version.
4. **«المراجعة والمحاكاة»**: preview selected population, regional impact, needs/duplicates, inventory feasibility. Generate draft list but no approval.

**List screen:** compact server-side paginated table with tabs/views «الكشف الأساسي», «الاحتياط», «للمراجعة/مستبعدون»; columns beneficiary, guardian when relevant, area, score, reason, last comparable aid, allocation, verification state. Clicking a candidate opens a right-hand explanation/benefit side panel, preserving filters. Export is authorized, scoped and audited. A visible status such as «مسودة», «بانتظار اعتماد المدير التنفيذي», «معتمد ومقفل», «تعديل بانتظار موافقة المدير التنفيذي» must prevent confusion.

CEO sees an approval detail view displaying diff, capacity and quota changes and impact on already-issued goods before action. Managers see **«طلب تعديل»**, never **«اعتماد التعديل»**. The list is never considered delivered simply because it is approved.

## 10. REPORTS, DONORS, AND EXPORTS

Reuse unified filter semantics. Reports: registered/approved orphans, guardians and direct relationships, geographic/age/orphan-type distribution, data-quality rates, sponsorship coverage, campaign candidates vs approved vs delivered, stock reconciliations, benefits, review-cycle time, workloads/overdue cases, donor-specific impact. Explicitly distinguish unique beneficiaries, number of delivery transactions, and total delivered units. Use Excel/PDF/CSV/print as permitted. Donor-facing reports default to **aggregates or minimized identifiers** and disclose personal data only with appropriate authorization and purpose. Record export audit information. Data must be real, time-scoped and permission-scoped.

## 11. EXCEL REIMPORT WITH STAGING

Design a robust importer to re-import the original workbook after rebuild. For the known workbook, **only worksheet «جميع المستفيدين»** is authorized for that import; ignore «غزة والشمال». Validate source columns, preserve original values (including string identity numbers and leading zeros), source row provenance, detect collisions, and show a full pre-commit review report. The source previously contained approximately 48,518 rows; this is not a target number to fabricate. Do NOT auto-approve claimed guardianships, infer missing gender, derive verified legal guardianship from parent names, or create operational child records without a linked guardian. Invalid/incomplete rows stay in staging for review. Make the importer resumable, idempotent, and efficient for >48k records. Import and cleanup operations must be auditable.

## 12. DATA MODEL / POSTGRESQL DESIGN CONTRACT

Produce an ERD and use existing table relationships where possible; candidate domains:
- orphans and guardian persons, `orphan_guardianships` (direct child↔guardian relationship with evidence/history), household social association, documents and reviews;
- portal guardian applications and child-specific relationship claims; assignments, reminders and periodic assessments;
- sponsor/commitment/sponsorship links and finance reference integration;
- campaigns, funding/resources, geographic quotas, item variants, scoring criteria and scale, immutable rule versions;
- nomination runs, candidate score explanations + data snapshots, lists and immutable list versions, executive approvals/change requests, appeals, cross-campaign duplicate warnings;
- inventory reservations and fulfillment/delivery references, idempotency records, append-only corrections, audited completed benefits;
- staged spreadsheet batches/rows/issues and import reconciliation; reporting/export audit.

Do not create all these tables blindly: **inventory, accounting, RBAC, assignments, audit, and existing delivery** should reuse shared services. Define foreign keys/unique constraints, active guardianship invariant, no-orphan-without-guardian transaction rules, version immutability, correct `ON DELETE` behavior, indexes and permission boundaries. Explain which sources of truth live in other modules. Do not store the same fact redundantly in multiple operational ledgers.

## 13. ACCEPTANCE TESTS (MANDATORY)

Implement automated tests with synthetic fixtures and run them. At minimum verify:

1. Cannot create operational orphan without guardian relation; creating linked guardian + child atomically works.
2. Guardian portal account approval does NOT expose unapproved children's records.
3. A guardian can be linked to different households; household membership does not grant guardianship.
4. Staff can search by *exact* National ID (preserve leading zeros) and four-part Arabic names; filters combine correctly, maintain navigation state and data permissions.
5. Suspicious/placeholder identity and duplicate-name cases are surfaced, never silently merged.
6. Manager dashboard uses valid distinct counts for staged vs approved, sponsorship vs delivered; chart drill-down reconciles with filtered registry counts.
7. Portal requests can be assigned, reviewed with checklist, and approved/rejected per individual child claim, retaining history.
8. Campaign general, weighted and hybrid runs produce explainable results with reproducible frozen input/rule versions; unknown input follows configured review policy.
9. Candidate selection respects target unit, quota, item size/SKU and available inventory; no reserve/list duplicates beyond allowed quantity.
10. **Non-executive actors cannot approve first lists or modifications** through UI or direct API; executive approval applies to exact version/diff; approved versions cannot be mutated silently.
11. Appeals and cross-campaign duplicate alerts do not automatically amend approved lists or deny benefits.
12. Offline repeated sync, retries and concurrent submissions do not double-deliver, double-decrement stock, or duplicate benefit history.
13. Corrective events preserve the original delivery/audit trail.
14. Donor reports and exports respect scopes and do not expose unrelated child personal data.
15. Legacy module removal does not break accounting, Stores, common RBAC, organization hierarchy or beneficiary portal infrastructure.
16. Test using realistic volume (>48k synthetic staged records) and report measured SQL/search/filter timings; avoid N+1 loading.

## 14. IMPLEMENTATION SEQUENCE

**Phase 0 — Read-only audit:** inventory legacy module; show dependency/deletion matrix; verify actual repository stack, DB and integrations; identify shared vs disposable components.  
**Phase 1 — Foundation:** retire legacy-exclusive code/data in controlled sequence; set up department reference catalogs, authorization mapping, domain schema, audit, tests.  
**Phase 2 — Registry:** guardian/orphan CRUD, direct relationship, replacement, dedupe warnings, unified cards/table + side preview + search, profiles.  
**Phase 3 — Portal review:** incoming applications, assignments, checklists, account vs individual guardianship approvals, appeals.  
**Phase 4 — Dashboards:** role-adaptive manager/employee homepage, KPI definitions, geographic and age graphs with drill-down, centralized action queue and performance metrics.  
**Phase 5 — Sponsorship/aid:** commitment and coverage with Finance references; completed benefit history.  
**Phase 6 — Campaign engine:** wizard, rule config/versions, simulations, list creation/reserve, exclusive executive approvals and change requests, stock integration, field/offline delivery and reconciliation.  
**Phase 7 — Re-import and reports:** staging importer, validation, manual review, real filtered reports/exports and donor reports; comprehensive regression/security/performance review.

Within each phase: keep functionality deployable/testable, verify permissions server-side, and report exactly what was completed. Do not fabricate successful tests, deployed status, metrics, or external integrations. Maintain a concise changeset/decision log and list all migrations. Avoid unbounded work beyond the current phase.

## 15. FINAL REPORT FORMAT

At the end of each implementation phase, return an **Arabic** report containing: completed user workflows, reused vs removed legacy components, files changed, migrations and database impact, tests actually executed and results, performance observations, authorization/security behavior, known blockers, and the next safe phase. Include a clear summary of the frontend interactions implemented versus backend operations verified.

## EXECUTE

Start now by inspecting the repository and producing the Phase 0 dependency/deletion map, proposed ERD, role-to-action matrix, screen route map and implementation checklist. Then carry out the implementation in the order above without unnecessary UX redesign debates. The accepted requirements in this document are the baseline: **easy daily staff operations, correct direct guardianship, an explanation-driven campaign nomination engine, executive-director-only approval of initial and changed beneficiary lists, verified fulfillment, and reliable beneficiary history.**


---

# AIMS 2.0 — Detailed Person, Family, Education & Health Data Model

**Mandatory addendum to:** `AIMS_Claude_Orphans_Sponsorships_2_0_Final_Implementation_Prompt.md`  
**Purpose:** Expand the production registry into complete, structured, auditable profiles for the orphan, legal guardian, deceased father, and mother.  
**Interface:** Arabic, RTL. **Implementation:** English identifiers in Laravel/PostgreSQL, integrated with the existing AIMS codebase.

> **Priority:** This addendum supersedes any prior minimal profile forms in the main prompt. Keep all previously approved business rules, executive-director-only campaign-list approvals, six navigation entries, and separation of guardianship from household membership. Do **not** assume the mother is deceased; support living, deceased, missing, and unknown/undetermined status with appropriate evidence and history. Do not assume the legal guardian is the mother, father, or co-resident household member.

## 1. Guiding principles

1. A person is stored **once**, even if the same father, mother, or guardian appears across several siblings. Use a canonical `persons` identity (or reuse an existing shared entity) with typed roles/relationships; **avoid duplicate parent records per orphan**. Preserve historical name/identity changes with provenance.
2. The orphan's own record is separate from the person's shared identity, guardianship records, parent–child relationships, household membership, social assessment, benefits and sponsorships.
3. Every operational orphan profile must link to a guardian relationship, which can be **pending verification**, but granting portal access requires **verified and approved child-specific authority**. Imported unverified guardianship is a claim, not legal proof.
4. Required fields are minimal for initial registration; optional detailed sections can be progressively completed. Display completeness per section without fabricating values or blocking urgent aid solely because an optional document is missing. Differentiate `not_provided`, `unknown`, `not_applicable`, `unverified`, and `verified`.
5. Never assume family status, income, disability, medical diagnosis, orphanhood type, nationality, or the father's pre-death economic status from names, proxy correlations, or incomplete Excel data.
6. Use dynamic reference dictionaries managed through «ثوابت النظام» for legally/operationally configurable values; keep safety invariants and integrity constraints in code/database.
7. All sensitive fields must have access policies, change history, lawful-purpose boundaries, retention controls, and controlled exports. Health and child data are particularly sensitive. Use the *minimum needed* in nomination, exports, and donor reports.

## 2. Orphan profile — «ملف اليتيم»

### A. Personal and civil identity — «البيانات الأساسية»
- Internal orphan reference, national ID or alternative ID type/number, ID validity/verification state, registration date/source, active/archive state.
- Structured name: first, father, grandfather, family; additional components and alternate spellings if applicable; Arabic display name and optional English spelling; honor names as entered, never infer components from concatenated strings.
- Date of birth, declared/verified birth date, derived age (not stored as authoritative), sex/gender field per organization policy, nationality/citizenship (if necessary), place of birth.
- Civil registry information and proof source when available; identity documents with issue/expiry and verification status.
- Primary contact via authorized guardian, not automatically the child's phone; optional safe child contact with access restrictions and purpose.
- Current status and file completeness, notes divided into operational vs restricted.

### B. Parents and legal/family relations — «البيانات الأسرية»
- Linked father and mother person records with link type and evidence.
- Father status including date of death (if verified), location/cause **only if specifically required and authorized**; date/source of verification and documents. Never require cause of death for ordinary aid eligibility.
- Mother status (living/deceased/missing/unknown), contact if appropriate, remarriage status **only when genuinely necessary for a defined program and policy**, and documentary sources.
- Current guardian(s), role/relationship to child, guardianship legal status, effective dates, basis/evidence, verified by/at, historical guardian relationships and transitions.
- Household membership and actual residence separately from guardianship. Handle siblings linked to both parents and potentially different households/guardians.
- Child's general orphanhood category (e.g. father deceased, mother deceased, both) derived from **verified parental statuses** when possible, otherwise flagged as provisional; support organization-specific special classifications as dynamic policy, not hardcoded.

### C. Residence and displacement — «العنوان والنزوح»
- Original address, current address, governorate → city → neighborhood cascading references and optional address detail/landmark; current location verified date.
- Displacement state, dates and episodes/history, shelter type, stability, access to basic services, occupancy/tenure as authorized; avoid forced precise geolocation.
- Household member count, dependents count, basic living conditions; versioned social-visit assessments and validity dates.

### D. Education — «البيانات التعليمية»
- Current educational stage, grade/class, school/institution, education status (enrolled/interrupted/not enrolled/completed/etc), educational year, enrollment/transfer dates.
- School type and attendance/absence (where needed), academic performance/achievement summary, learning difficulties or educational support needs (avoid inferred medical diagnoses).
- School supplies, transportation, fees, devices/internet access, special educational accommodations, requested vs delivered educational assistance.
- Multiple year-by-year enrollment/education records; do not overwrite history when moving grades or schools. Dynamic stages/classes; no hardcoded grades or fixed age assumptions. Attach certificates and school proof with controlled access.

### E. Health and support needs — «البيانات الصحية»
- Optional high-level care/support needs relevant to the service: health status summary, chronic care needs, disability/support accommodations, medication assistance needs, assistive devices, follow-up due date, healthcare access barrier.
- For diagnoses, treatments, medication lists, laboratory attachments, or clinical reports: collect only if needed for a specific service with proper legal authorization; structured, limited-access records with who recorded the information, source, review date, and verification.
- Emergency contact and medical emergency instructions only where operationally justified; do not allow a general employee to browse sensitive clinical documents by default.
- Treat self-reported, caregiver-reported and clinician-verified medical facts differently; no automatic clinical eligibility decisions.

### F. Social/economic evaluation, sponsorship and benefits
- Social assessment date, author, verified evidence, criteria version and score version; household financial assessment belongs to household or current guardian as appropriate, not repeated as isolated facts on each child.
- Sponsorship coverage with dates, commitment vs actual payments, programs/campaign nominations, approved aid, confirmed deliveries, appeal history, service referral, caseworker assignments.
- Clear section completeness states and last updated-by/date on each relevant section.

## 3. Guardian profile — «ملف الوصي»

### A. Identity, contact, and legal eligibility
- Canonical person identity: structured four-part name, IDs and verification, birth date, sex/gender per policy, nationality, current address and contact channels.
- Legal relationship and **per-child** authority: parent/grandparent/relative/appointed guardian/other dynamic roles, legal basis, authority scope, start/end dates, court/administrative documentation when applicable.
- Guardian-portal account status **separate** from each per-child guardianship approval; access only to children for whom approved legal authority is current.
- Other children under care, including cross-household links; do not treat household membership as legal authority.

### B. Social/economic circumstances
- Employment status and type, number of dependents, number of earners, income ranges and source/date/verification (avoid unnecessarily exact amounts), ongoing obligations, housing stability and displacement history.
- Social worker assessments, assistance/support needs, communication preferences, verified vs reported details, dates of updates.
- Optional education level and skills only when relevant to aid/service provision.

### C. Health (only if necessary)
- Optional high-level disability, mobility/care support needs impacting caregiving or eligibility, **not mandatory sensitive diagnoses**. Apply stricter permission checks.

### D. Relationships and historical records
- Per-child relations and approval statuses, children in current care, former guardianships, reported and verified documents, guardian requests/appeals, actual goods received **on behalf of** children or household (not automatically credited to the guardian personally).

## 4. Deceased father — «بيانات الأب المتوفى»

- Shared father person record: structured name, national/alternative ID, date of birth when known, nationality, original/home residence and identity evidence.
- Death event: reported date/place and documentary proof, verification state, supporting authority/reference, changes and history; capture cause only if necessary, approved, restricted.
- **Economic situation before death (time-specific):** occupation, employment sector/status, estimated historical income band (not a guessed exact value), dependents supported, primary/secondary livelihood source, relevant support/properties where allowed, assessment source and confidence/verification, date of observation and time period.
- Father's economic history is a contextual social-evaluation factor, **not automatic proof of current child need**. Current deprivation, dependents and guardian/household circumstances should be assessed separately.
- All siblings should link to one father's record; correcting an authoritative fact can update displayed profile data while preserving historical campaign snapshots and past assessment decisions.

## 5. Mother — «بيانات الأم»

- Shared mother person record: four-part name, identifiers, birth date where known, nationality, contact/address, verification/source.
- Current life status, death/missing status only if supported by evidence, relevant death event if applicable, and historical status changes.
- Relationship to each child and current living arrangement, current employment and economic circumstances, income bands/dependents and displacement/social needs if required.
- Mother may also be the guardian, but **mother identity does not automatically grant system-level legal guardian permission**. Create/verify explicit child-specific guardianship link according to documented authority and organization policy.
- Any marital/remarriage data are optional, only with legitimate program purpose and least-privilege restrictions. Do not use stereotypes to automatically exclude applicants.
- Education and health assistance needs may be captured in their appropriate sections where relevant, but never mandate unnecessary intimate medical information.

## 6. Family and household model — «الأسرة»

- Distinguish **biological/legal parentage**, **guardian authority**, and **shared household/residence** as separate graphs. Do not conflate the three.
- Household: members, current responsible adult for contact (not necessarily guardian), address/displacement episodes, member/dependent counts derived where possible, social/economic assessments, visits and family-level benefits.
- Household social aid is booked **once** against its actual beneficiary household, not multiplied across associated children. Child-specific clothing is booked per child, even if handed to a single guardian.
- Support household membership changes over time, shared parents across siblings, unrelated children under one guardian, and a guardian associated with more than one household.

## 7. Detailed profile UI — few tabs, progressive disclosure

Use one unified design system and **no new main sidebar entries**. Under «إدارة الأيتام والأوصياء» implement:

**Orphan profile:** header (photo if authorized, ID masked, name, file/completeness, main guardian); tabs/sections: `البيانات الأساسية`, `الأسرة والوصاية`, `التعليم`, `الصحة`, `السكن والنزوح`, `التقييم الاجتماعي`, `الكفالات والمساعدات`, `المستندات وسجل التغييرات`. Parent mini-cards for `الأب المتوفى` and `الأم` open a contextual side panel and authorized dedicated detail view without duplicating person records.

**Guardian profile:** header + `البيانات الأساسية`, `الأيتام المرتبطون`, `الوضع الاجتماعي والاقتصادي`, `السكن والنزوح`, `المستندات`, `الطلبات وسجل التغييرات`. Show children and legal approval state for each relationship; do not expose clinical details in compact cards.

**Create flow:** guardian first or choose existing verified/unverified guardian; create orphan and explicit relationship atomically; basic minimums first, complete other sections later. Allow staff to save a pending-verification guardianship while preventing unauthorized portal access.

**UI ergonomics:** autosave drafts where safe, explicit final save/confirmation for legal decisions, field-level validation, documents status, dynamic reference dropdowns, repeatable rows for schooling/displacement, dependent dropdowns for geography. Show explicit `غير متوفر` / `بانتظار التحقق` rather than silent null interpretation. Use 4-field advanced name search for child and guardian. Desktop side preview/mobile drawer; preserve search filters.

## 8. PostgreSQL/Laravel implementation contract

Do NOT blindly create these tables; audit existing naming, keys and data first. Prefer extension or reuse over duplicates. Example normalized conceptual entities:

- `persons`, `person_identifiers`, `person_contacts`, `person_documents`, `person_identity_history` (or existing shared person abstraction).
- `orphans` (domain-specific child registration), `parent_child_relationships` with father/mother relationship type and confidence/evidence, `person_death_events`, `guardian_child_relationships` with status, evidence, approval, effective timestamps, audit.
- `households`, `household_memberships` (effective dates), `household_addresses`, `displacement_episodes`, `household_assessments`.
- `orphan_education_records`, `orphan_education_support_needs`, `school_references` (when there is no existing directory).
- `person_care_needs` and strictly permissioned `person_health_records` / secure document metadata (avoid exposing diagnoses in generic person columns).
- `person_economic_assessments` with `assessed_person_id`, relevant reference date/time period, income **bands** and source/evidence; `household_economic_assessments` where appropriate. Support father's **pre-death** historical assessment explicitly.
- `social_assessments`, `person_role_links`, domain-specific audited verification and document associations. Reuse the existing AIMS `departments`, `locations`, RBAC, audit engine, approvals, shared document/media capabilities.

Data rules:
- FK constraints or validated domain types; prevent phantom references; unique authoritative identification within appropriate context while handling legitimate provisional/alternate IDs; normalize Arabic for search but never alter original legal display names; store IDs as strings preserving leading zero.
- Unique-current guardianship constraints must **not** forbid multiple valid simultaneous guardians if policy allows, but require at least one associated guardian for each operational orphan. Use DB transaction/service to create/reassign links without a gap; protect against races and stale approvals.
- Use effective dating instead of destructive overwrites for guardianship, enrollment, displacement, economic status, maternal status, and documented events.
- Flexible indexing for IDs, structured name components, relational filters, linked parent/guardian, location, schooling, social assessments; avoid unnecessary materialized duplication.
- Access control: server-side policies per section and sensitive field, encryption at rest where supported, encrypted storage for medical documents, access logging for sensitive record reads, controlled retention/export, and identity-masked previews.
- The government API is **not assumed available**; build optional adapter/interface but do not falsely claim civil-registry or guardianship verification without an actual implemented integration.

## 9. Campaign-scoring integration and data quality

- Campaign nomination engine may read **authorized, relevant, verified/versioned** orphanhood status, dependent count, father's historical pre-death economics, current guardian/household economics, displacement, schooling, selected support needs, and previous benefit history **only when the campaign's published eligibility rules require it**.
- Do not directly consume raw medical diagnoses in general relief nomination scoring; if a healthcare-specific program requires a medical eligibility flag, provide a restricted verified eligibility decision rather than broad record access.
- Snapshot each used fact, its verification/provenance, time and weight in `campaign_candidate_snapshots`, as already required in the main prompt.
- Separate `missing_data`, `failed_eligibility`, and `insufficient_priority`. Missing health/economic data must not be converted silently to zero or auto-disqualify.
- No modification of any approved campaign beneficiary list, initial or subsequent, without **exclusive executive director approval**, producing an immutable new list version.

## 10. Acceptance tests (additive to the main prompt)

1. Register one father and mother once; link multiple siblings without duplicating parent person rows.
2. Register a living mother who is not a verified guardian; her portal account cannot access the child until specific guardianship approval.
3. Register a child with an existing guardian whose legal relationship remains pending; allow internal draft, deny portal child access, and never persist an operational orphan without a guardian link.
4. Change a child's guardian, preserving the previous verified relationship, proof, effective dates and historic disbursements.
5. Record historic father's economic status **before death** without copying it as current family income; require source/verification markers.
6. Add 2 schooling years, transfers, educational support needs, and a displacement episode without overwriting prior history.
7. Add a restricted health-support record; an ordinary employee, donor export and unprivileged portal user cannot read clinical fields or documents.
8. Import incomplete Excel rows; quarantine missing parents/guardian links and never infer non-existent names, gender or diagnoses.
9. Run nomination using verified social/guardian/household data; demonstrate explanation, evidence timestamp and immutable campaign snapshot.
10. Verify that family food aid counts once for the household, while each child's clothing is a separate delivery even if received by the same guardian.
11. Verify filtered ID/name/location and education-status searches are accurate and efficient and fully permission-scoped.
12. Preserve all prior executive-only list approval, offline delivery idempotency, audit, and accounting/inventory integration tests.

## 11. Deliverable and execution order

1. Inspect current real repo and schema; produce a field-to-table mapping and migration plan before editing.
2. Update **ERD, SRS, roles matrix, validation rules and Arabic UI wireframes** to incorporate the four detailed profiles.
3. Implement reusable person/identity/relationship/domain models and migrations safely, then full forms, APIs, policies, audits, search, reports and tests.
4. Seed only dynamic reference definitions when needed, **never sample medical/identity data in production**.
5. Integrate with guardian portal, campaign scoring, social assessment and Excel import while keeping the six-entry navigation and approved executive-only list controls.
6. Report actual implemented functions, real test results and gaps. Do not claim a feature works without executable evidence.


---

# 16. SMART IMPORT OF DATA AND LISTS — FINAL APPROVED ADDITION

**This section is mandatory and supersedes earlier import UI placement instructions wherever there is a conflict.** The new action is named **«الاستيراد الذكي»**, with extended label **«استيراد البيانات والكشوفات»** where space allows. It must be a real, secure feature, not an ornamental icon or a simple upload form.

## 16.1 Placement and icon

- Preserve exactly the **six previously approved main sidebar entries**. Do not add a seventh sidebar section just for importing.
- Create a recognizable **«الاستيراد الذكي»** icon/action in:
  1. The role-authorized quick actions of «الرئيسية».
  2. The primary action area of «إدارة الأيتام والأوصياء».
  3. The contextual import actions in «الحملات الإغاثية» for candidate/source sheets.
  4. Appropriate import/source-list entry points of «التقارير والإحصائيات».
  5. The heading of the smart import wizard.
- Prefer existing design-system vector icons for actual small-size navigation/action usage: `file-spreadsheet` + `arrow-down-to-line` or `file-input`, accompanied by a subtle `sparkles` indicator; use the icon library actually installed in the repository. Do **not** use emoji icons, low-resolution bitmap thumbnails in tiny toolbars, or unrelated icons.
- If a branded illustrative illustration is desired for the wizard's empty state, its concept is a sheet/table with a down-arrow and a subtle smart-automation accent. Do not require external runtime assets or introduce dependencies solely for decoration.
- The icon is secondary to the clear text label **«الاستيراد الذكي»**. It must support RTL, keyboard navigation, screen readers, dark/light themes if present, and responsive sizing.
- Helper text: **«استيراد بيانات الأيتام والأوصياء والكشوفات مع التحقق المسبق واكتشاف الأخطاء والتكرار»**.
- Permission-gate the UI AND the underlying endpoints; having access to the icon must not confer approval or bulk-edit rights.

## 16.2 Supported document types and data domains

Support structured **XLSX and CSV** files first; XLS may be supported only if existing libraries allow safe parsing. Do not imply that PDF/image documents can be reliably converted into structured registry records without a separately validated extraction workflow.

**Import purpose must be chosen explicitly**, rather than guessing it silently:
- New orphan/guardian/family/parent registry data.
- Updates to existing records (reviewable, controlled field-level changes).
- Educational, health, residence, socioeconomic or other profile enrichments, subject to higher sensitivity controls.
- Campaign candidate/source sheets, area allocations and garment-size/quantity sheets.
- Delivery-reconciliation lists, if the existing delivery architecture and proof-of-delivery rules authorize them.
- Donor-provided recipient lists, which are **proposals pending reconciliation**, not automatically approved institute beneficiaries.

Do NOT allow spreadsheet import to substitute for verified government-source data, legal guardianship proof, executive director approval, accounting entries, or confirmed physical delivery.

## 16.3 Guided smart import wizard

Keep the wizard compact, in Arabic RTL, using 6 logical stages (may be shown as a responsive stepper):
1. **«رفع الملف»**: choose domain/purpose, upload, file size and type validation, choose worksheet, select expected encoding for CSV.
2. **«مطابقة الأعمدة»**: preview headers and sample rows, use configurable aliases for Arabic/English columns, recognize child vs guardian vs father vs mother vs household vs campaign fields; present editable mappings and data types, never silently map ambiguous columns.
3. **«الفحص الذكي»**: validate shape, duplicate rows, national-ID formatting, invalid placeholders, legal relationship consistency, missing mandatory guardian associations, conflicting record identities, dates, ranges, education/health field constraints, and incompatible campaign quantities/sizes. Normalize Arabic variants for name searching but preserve original names and IDs; IDs are strings and preserve leading zeros.
4. **«المراجعة والتعارضات»**: categorize each row as **«جاهز للاستيراد»**, **«يحتاج مراجعة»**, **«تعارض»**, **«مكرر»**, **«مرفوض»**; show source row number, precise reason, matching existing records and proposed change diff. Allow authorized corrections to staged values, not destructive edits to source-of-truth records.
5. **«المعاينة والاعتماد»**: show summary of create/update/skip/reject counts, parent and guardian links, sensitive data changes, permissions, and explicit approval gates. No database mutations at dry-run/preview time.
6. **«التنفيذ والنتيجة»**: process accepted rows using queued, chunked and resumable jobs; report success/errors per row, downloadable error report, import batch audit, status/progress and safe retry.

**For the previously analyzed legacy dataset:** the original Excel workbook `قيم 22.xlsx` may be reimported later and its worksheet **«جميع المستفيدين»** is the scope of the historical import, when that actual workbook is supplied. Never fabricate a workbook or claim it is accessible. Previously disposable staged rows do not constitute approved orphans or guardians.

## 16.4 Referential integrity and sensitive records

- A guardian may exist independently, but a child must not become an operational orphan record without at least one directly linked guardian relationship (verified or explicitly pending verification).
- Relationship imports are *claims*, never automatically legally verified by name, surname, household ID, spreadsheet row proximity, or shared phone.
- Do not duplicate shared people when importing siblings or linking a deceased father/mother; propose matches and have staff review collisions.
- Keep separate dimensions for the orphan file state, completion state, guardian relationship verification, portal account state, and sponsorship state.
- Importing the mother does not imply she is the legal guardian. Keep mother/guardian identities and their relationship to the child separate.
- Health details are particularly sensitive: permission-scope every column, mask restricted preview/export fields, use secure storage and audit access and mutations; reject unauthorized mapping.
- Store original source documents securely with access controls and retention policy; avoid raw PII/health data in application logs or errors.

## 16.5 Critical campaign-list and executive-approval protection

**The user-approved exclusive executive director rule applies equally to spreadsheet-imported lists.**

- Importing a list of proposed campaign candidates must create **staging/proposals or a new draft**, never directly create an approved/distributable list.
- An initial campaign list becomes formally distributable **only after explicit executive director approval**.
- Once a campaign list is approved, imported replacements, exclusions, additions, sizes, quantities, location quotas, and reorderings must be represented by a **change request** with a before/after diff, reason, and proposed new list version.
- **Only the executive director may approve** such a change request. The department head, system administrator, and normal staff may not use an import operation to bypass approval, even via administrative, queue, or API paths.
- Preserve all approved list versions, director approval evidence, timestamps, and proposed changes. Prevent direct mutation of immutable approved versions.
- The imported list must also be checked against existing campaign nominations, previous actual benefits, active reservations, outstanding deliveries, quantity/size/region constraints, and potential duplicates across campaigns; conflicts must be reviewable and not silently auto-excluded.
- Receiving or importing a delivery/reconciliation file must NOT set an orphan's actual benefit as delivered without validated delivery evidence consistent with the approved offline workflow.
- Prefer idempotent external reference keys, per-row hashes, unique constraints, and transactional state transitions for bulk operations.

## 16.6 Suggested technical implementation (adapt to actual repo)

Review existing Laravel queues, PostgreSQL migration conventions, file storage, workbook parser package, import permissions, event/audit framework, and module boundaries before choosing exact classes or table names.

Conceptual entities (reuse existing infrastructure wherever suitable):
- `import_batches`: owner, purpose, file metadata/checksum, worksheet, mapping version, statuses, summary, timestamps.
- `import_staged_rows`: source row, sanitized preview, staged normalized fields, match candidates, validation/conflict outcomes, proposed action.
- `import_row_issues`: issue code, severity, affected field, explanation and resolution state.
- `import_mapping_profiles`: reusable source-column-to-domain mapping templates, optional and scoped.
- `import_execution_events`: performed operations, row idempotency keys, rollback/correction references, actor and audit details.

Security and reliability requirements: validate upload size, content and MIME independently of filename; avoid formula injection on subsequent CSV exports; protect against spreadsheet bombs and malicious ZIP/XML payloads; use streaming/chunked processing; do not trust external formulas as reliable data; no mass-assignment bypasses; use secure jobs with per-row permission/review rechecks; race-safe unique constraints; safe retry and accurate batch reconciliation.

Do not create parallel shared identity, guardianship, household, inventory, or campaign approval sources of truth to support importing.

## 16.7 Smart import acceptance tests

The feature is not complete until all are demonstrated:
1. Smart Import action/icon is discoverable in the approved places; no seventh main sidebar item is introduced.
2. Arabic RTL wizard correctly imports XLSX/CSV through preview, mapping, validation, review and permitted commit.
3. Name normalization preserves original names; national IDs retain leading zeros and reject invalid placeholders.
4. Source rows for orphan records missing a direct guardian association remain staged and cannot silently create operational child profiles.
5. Duplicate persons and parent/guardian identity conflicts are surfaced for human review; siblings link to shared parent identities when verified.
6. A CSV with mixed mother and guardian details does not falsely confer guardianship.
7. Sensitive medical and socioeconomic columns require server-side access rights, even when hidden in the UI.
8. Uploading an initial campaign roster does not make it distributable without the executive director's approval.
9. Uploading changes to an already approved campaign roster creates a pending director-only change request, with zero premature edits to the approved version.
10. Repeating a batch, queue retry, or offline sync cannot duplicate beneficiaries, benefits, deliveries, reservations or approved decisions.
11. Staff can inspect row-level problems, export a permission-safe error report, correct staging rows and retry safely.
12. The output report shows exact created, updated, skipped, conflicted, rejected and failed counts; test these against the real persisted records.
13. Existing AIMS inventory/accounting/portal/other department functionality remains intact.

## 16.8 Final implementation instruction to Claude

Implement this Smart Import addition as an **integrated part of the full AIMS 2.0 rebuild**, not as a separate toy page or standalone mini-app. Inspect the existing repository first, provide the actual existing dependencies and schema, implement production-ready models/migrations/services/controllers/jobs/permissions/Arabic RTL UI/tests, run relevant checks, and report real executed results and outstanding limitations. Do not claim a test, integration or dataset import occurred unless it was actually executed and verified.

---

## FINAL PRIORITY AND EXECUTION ORDER

Treat this entire document as the authoritative consolidated prompt for the Orphans & Sponsorships Department. The latest decisions override older contradictory wording, especially:
- Exactly six primary sidebar entries.
- Detailed relational profiles for the orphan, guardian, deceased father and mother.
- Three nomination modes, hybrid default, versioned criteria and explainable results.
- Exclusive **executive director** approval for both initial campaign lists and every later approved-list change.
- No actual benefit recorded until evidenced delivery.
- Smart Import with a visible icon/entry point inside existing six sections, not a seventh section.
- No automatic approval of legal guardianship, imported donor rosters, or imported actual deliveries.
- Preserve existing shared AIMS infrastructure, data, and integrations.

Start with a real repository audit and implementation map; then execute the work in verified phases. Do not merely rephrase this document or deliver static UI mockups. Produce working code and tests, and report which features are actually implemented versus planned.
