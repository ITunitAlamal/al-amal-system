# CLAUDE CODE — AIMS 2.0 Beneficiary Portal Rebuild

## Mission
Act as a senior Laravel architect, PostgreSQL database architect, security engineer, and Arabic RTL UI/UX specialist. **Inspect and implement** a production-quality beneficiary self-service portal for **Al-Amal Institute for Orphans**, Gaza, within the EXISTING AIMS Laravel codebase at `C:\Projects\al-amal-system`.

Portal hostname: `portal.al-amal.ps`.
Staff ERP hostname: `system.al-amal.ps`.
Public organizational website: `al-amal.ps` (separate Laravel application, OUT OF SCOPE).

**IMPORTANT: This is an implementation assignment, not merely a design exercise.** Start by auditing actual repository files, migrations, models, controllers, policies, authorization, views, components, asset pipeline, tests, existing portal implementation, and deployed topology. Create a concise implementation plan and execute in small verified phases. Never invent pre-existing services or say tests passed without running them.

## Non-negotiable architectural decisions
1. Beneficiary portal is a **logical module within the SAME existing AIMS Laravel application** (modular monolith), not a new Laravel project. Reuse PostgreSQL entities and domain services; isolate browser-facing routes, UI, guards/session boundaries, policies and permissions.
2. **Arabic is the entire public-facing UI language**, including labels, field hints, notifications, email/SMS templates where applicable, error messages, accessible names and confirmation dialogs. RTL throughout. Follow current ERP component stack; DO NOT assume Blade, Vue, Bootstrap, MySQL or a package without inspecting repository. PostgreSQL is the established AIMS database engine; validate actual configured connection.
3. Maintain and protect existing records, verified orphans/guardianship, employee workflow, accounting, stock, aid-delivery logs, audit logs and other ERP departments. Do not blanket-drop tables, truncate production data or reset DB. Changes must be reversible and migration-safe.
4. Self-registration by new guardian/parent is **allowed even if no prior official institute record exists**. However, a self-declared relationship or family household DOES NOT establish legal guardianship or permission to access official orphan records. Each child access grant requires a verified, approved per-child legal guardian relationship and explicit authorization. An account may be approved independently of its child links.
5. Household/family describes residential and socioeconomic context; it is not legal access control. A child can have historical guardian changes and a guardian may have children across different households. Reuse people and parent identities for siblings; avoid duplicate mother/father records.
6. Do not expose internal social assessment scoring, campaign priority rankings, confidential reviewer notes, other beneficiaries' information or unapproved campaign lists through the portal.
7. Campaign lists, both initial and subsequent changes, require **EXCLUSIVE executive director approval**. The portal may show approved, explicitly publishable assistance statuses but MUST NOT act as an alternative approval path. Nomination, allocation, reservation and actual delivery are separate states; do not report an aid benefit as delivered without verified actual delivery evidence.
8. Human-driven review; external identity or government interfaces may be used only if credentials, contracts and endpoints really exist. NEVER invent a governmental API or assume spreadsheet data proves legal guardianship.

## Product goal / UX design principles
Create a clean, premium, mobile-first, low-bandwidth service portal, visibly distinct from the employee ERP but consistent with Al-Amal blue/green/white brand. Accessible RTL Arabic typography, high contrast, obvious next actions, no heavy animation, no artificial dashboard counters or dummy people in production. Keep the layout short and easy for non-technical guardians using budget smartphones and intermittent connectivity in Gaza.

Primary authenticated navigation should contain exactly FIVE areas:
- **«الرئيسية»** — status, pending actions, latest trusted notices, quick links.
- **«أسرتي»** — applicant/guardian profile, registered family, parents, child records the account is permitted to see, original/current displacement addresses and controlled requests to update information.
- **«طلباتي»** — initial registration, document completion, correction requests, child-relationship applications, objections, status chronology.
- **«مساعداتي وكفالاتي»** — only publishable committed/confirmed support or realized benefits, with precise wording for each status; never show internal ranking or private donor/accounting data.
- **«الإشعارات»** — requests for additional info, decisions, actionable notices; preferences and logout live in profile menu.

No ERP-like sidebar for beneficiaries; use a mobile bottom bar / compact header when appropriate, familiar breadcrumb/back affordances, and consistent desktop navigation. Keep pages fast: optimize bundle, lazy-load bulky components, use loading/empty/error states, pagination, accessible focus, no blocking full-screen spinner for every request.

## Public entry pages and authentication
- Branded landing/welcome screen with two clear actions: **«تسجيل الدخول»** and **«إنشاء حساب»**, explanatory copy, privacy/contact resources, help/FAQ and accessible mobile design.
- Beneficiary authentication must be separate from employee privileges and sessions. Audit existing accounts/guards and choose safe architecture; prevent cross-subdomain cookie/session confusion. Implement proper rate limits, session rotation, CSRF, secure password reset, guarded account recovery, and secure phone verification **only when a real SMS/OTP service is configured**. Without an SMS provider, do not simulate an authenticated OTP or mark phone verified; expose clearly supported fallback/manual verification.
- Registration collects minimum account fields first (full name, national ID if appropriate, contact phone, password, declaration); allow a first-time applicant without an already-registered orphan profile; protect against enumeration in duplicate-account and password-reset errors. Do not force excessive sensitive details before saving an account.
- New accounts initially have only their own account and their own submitted drafts. Official family/child read access comes later through verified links.

## Family-based registration wizard (reuse approved AIMS domain model)
An applicant may save and submit ONE family-based request containing MULTIPLE child entries; an employee may initiate a request through the existing staff workflow without duplicating portal logic. The wizard must provide automatic server-side draft saving/recovery, last-step resume, progress indicator, Previous/Next, inline Arabic validation, selective step completion, accessible repeatable child cards and summary before final submission.

Stages and comprehensive fields:
1. **«بيانات الطلب»**: generated request number, source (guardian/parent vs staff), applicant full name, phone, relation to family, comments; server-controlled timestamps.
2. **«بيانات الأب»**: 4-part name (first/father/grandfather/family), national ID string, DOB, status alive/deceased/missing/unknown, conditional death or missing details, former occupation, former economic conditions / household income context and evidence/source. Do NOT assume father deceased for every orphan classification.
3. **«بيانات الأم»**: identity and 4-part name, DOB, alive/deceased/missing/unknown, marital status, conditional death/missing details, phones, education level and optional university specialization, work/employment and income, health/social context subject to privacy.
4. **«بيانات الأسرة»**: optional/verified household membership roster, total residents, male/female counts, studying and employed members, dependants, income sources and current household income, financial stress, external assistance, source/verification dates. Distinguish self-reported household counts from system-derived orphan counts. Validate coherent totals without blocking legitimate unknown demographic information.
5. **«بيانات الوصي»**: is mother guardian? (select existing person identity, not duplicate), otherwise complete guardian profile (4-part identity, national ID, date of birth, relation for EACH child, contact, occupation, education, income, residency). Upload legal evidence if available; all claims pending verification. Each child must have a guardian linked even in pending-verification state before it becomes an operational child record. An imported/draft application may be incomplete but not promoted to operational state without this link.
6. **«السكن والنزوح»**: original address (governorate → city → neighborhood, street, landmark, detailed address, ownership/housing type), current location separately, displacement yes/no, displaced since, displacement count/history, current housing type. NEVER overwrite original address when updated. Use editable dynamic system constants, NOT hard-coded governorates/regions.
7. **«بيانات الأيتام»** repeatable with prominent **«إضافة يتيم آخر»**: four-name parts, gender, national ID stored string preserving leading zeros, DOB, calculated age, birth location, optional photo, parent links, orphanhood classification derived/validated according to approved institute rules (paternal/maternal/double/special), education status and grade/school/type/nonattendance reason, health category/condition/disability/ongoing support needs (sensitive and narrowly visible), and external sponsorship provider/type. Distinguish parent verification from asserted orphan category; do not assert official eligibility merely from a dropdown.
8. **«الوثائق والمرفقات»**: optional in INITIAL submission. Identity documents, birth certificates, death certificates where applicable, mother/guardian IDs, legal custody evidence, medical records, photos and other documents. Private storage; role-gated signed/streamed access, MIME/content checking, size limits, malware precautions, safe file names and retention controls; ability to submit missing documents later.
9. **«المراجعة والإقرار»**: organized review screen, mandatory declaration of accuracy and notice/consent for verification and privacy, recorded applicant, time and submission version; explicit **«إرسال الطلب»**. Human-readable request reference and status.

Use in-context conditional fields with server-side conditional validation. All data fields must match actual models/approved profile schema after audit; preserve detailed orphan/mother/father/guardian data if already implemented. Use Form Requests or equivalent project pattern, transactional server processing, safe optimistic locking/version checks on edits. Never mass-create official data without review.

## Review and approval — reuse staff workflows
Staff reviews happen at `system.al-amal.ps`, using the existing **«الطلبات والمراجعات»** module. Portal users only see safe status and actionable requests. Proposed statuses: **«مسودة»**, **«تم تقديم الطلب»**, **«قيد المراجعة»**, **«بحاجة إلى استكمال البيانات»**, **«معتمد»**, **«مرفوض»**. Distinguish account approval, registration request approval, profile data completeness and per-child guardianship approval as SEPARATE workflows.
- Employee may request specific missing items with an Arabic explanation; beneficiary can upload/correct only requested fields without overwriting approved official records.
- Employee checks claimed IDs and duplicates, documents, child-by-child relationship verification, reviewer assignments and access scopes, then decides according to granted staff permissions.
- Approved family/child profiles should upsert/link to shared canonical records with unique identities and transactions; preserve source request snapshot and decisions. Do not create duplicates. Keep past guardian relationships and previous confirmed deliveries unchanged.
- Rejection includes reasons suitable for beneficiary, separately from confidential internal staff notes; keep audit trail and appeal path.

## «أسرتي» — read and controlled updates
Provide a clear family summary, guardian/contact profile, parents, permitted children (cards/list), school data, current/original residences and non-sensitive status. Allow photo/document additions only when authorized. For OFFICIAL records, changing phone, residence, school, household, health info, parental status or guardian relationship should initiate a **«طلب تعديل البيانات»** with proposed values and a before/after diff for the staff reviewer; define safely self-editable account preferences separately. Never auto-grant a new child relationship or change legal custody because a household member submits a request.

Only show children with currently APPROVED per-child guardian account associations. When custody expires/transfers, revoke child visibility immediately from server-side authorization checks and cached responses, while preserving the records and historical audit. Support multiple families/households as legally appropriate; avoid treating household ID as access scope.

## «طلباتي» and appeals
Show a compact timeline for each request, its type, submitted date, current status, required next action, staff response suitable for beneficiary, relevant document uploads and status notifications. Include initial registration, correction, missing-document fulfillment, guardianship relationship claims and objection/appeal submission. Appeals may initiate human review but NEVER auto-modify approved aid campaign lists; only the executive director can authorize approved campaign list changes. Do not reveal other applicants or their details.

## «مساعداتي وكفالاتي»
Integrate with existing canonical sponsorship, relief campaign, assignment and delivery services. Show only beneficiary-specific, publishable information and accurately distinguish:
- Application or internal candidacy: generally private/not guaranteed; avoid showing unapproved nominations.
- Approved/ready-to-collect assistance (only after institute chooses to publish and operational inventory/delivery readiness is verified).
- **«تم التسليم»** only on validated actual delivery event.
- Sponsor support summaries only if beneficiary-visible under policy; no staff accounting details or confidential scoring.
- User can see relevant date, assistance category, recipient, distribution reference and practical instructions, omitting sensitive third-party data.
Never fabricate balances or aid entitlements, duplicate family-level aid across children, or create benefit history from nominations/reservations.

## «الإشعارات» and communications
Create/extend queued in-app notifications for completed actions, requests for missing documents, approved/rejected decisions and publishable aid collection instructions. If SMS integration exists, use it lawfully with consent, templates and retries; otherwise keep SMS disabled, no fake sends. Notification links must validate account/record authorization at click time. Limit PII in notification payloads and public push messages. Add read/unread handling and action-based filtering.

## Low-bandwidth, resilience and performance
- Mobile-first; minimize JS and images, compress assets, paginate lists, lazy-load nonessential data. Avoid giant SPA bundles for simple forms if current architecture does not need them.
- Autosave at logical intervals/field blur when online, explicitly display **«تم حفظ المسودة»** or safe failure state, and do not promise saving before server confirmation. Retry lost network safely without duplicate submissions, with idempotency keys on mutations.
- Provide clear offline/error/reconnection UX. Optional PWA shell later, but NEVER persist medical reports, children's identity documents or sensitive family data unencrypted in service worker caches/local storage.
- Accessible keyboard flow, form field labels, explanatory errors, proper RTL numeral/telephone formatting, coherent date input handling, responsive 320px+ layouts and meaningful loading/success/error/empty states.

## Security, privacy, reliability
Threat-model public-facing workflows: IDOR/BOLA, cross-subdomain session leakage, account enumeration, sensitive exports, password reset abuse, large upload attacks, race conditions, replay/double-submission, cached private pages, CSRF/XSS and SQL injection. Use existing Laravel policies, authorization scopes, route model binding, middleware, server-side checks, guarded mass assignment, private document controllers and audit trails. Apply retention and least-privilege principles to health, national ID and deceased-parent data.

No admin/system or internal campaign list approval capabilities should be reachable through portal endpoints. No blanket permission based on family membership. No staff-only comments, executive approval data or other children's profiles in API responses. Ensure all API resources are policy-filtered before serialization.

## Implementation workflow — must execute
**Phase 0 — Audit and plan:** list actual Laravel/PHP/database/frontend tech; locate existing portal routes/files, auth, guardian model, registration schemas, role/policy framework, test suite, shared modules. Document risks, file change plan, reused components. Use existing migrations and data; do not infer table names from this prompt.
**Phase 1 — Architecture/security:** hostname routing, auth separation, session/cookie settings, module boundaries, policies, beneficiary data scopes and safe integration contracts.
**Phase 2 — Design system/public pages:** responsive layout, landing, login/create/reset, authenticated five-area navigation and dashboard.
**Phase 3 — Registration wizard:** comprehensive family/parent/guardian/orphan data, drafts, step validation, repeated children, optional private attachments, review and final submit.
**Phase 4 — Reviewer integration:** sync with existing AIMS staff request/review module, per-child guardianship approval, deduplication and safe upsert, completion requests.
**Phase 5 — Self-service:** family detail, permitted children, change requests, request status timelines, appeals, notifications, published assistance history.
**Phase 6 — Hardening and tests:** responsive and accessibility checks, security tests, network interruption/retry, performance budget, regression tests across employee ERP, shared DB and permissions.

After each phase run actual relevant tests and check that AIMS staff functions still work. Commit or checkpoint changes in small coherent increments if repo process permits. Continue implementation as far as tools/session allow; do not stop after merely delivering a plan. Do not produce a second unrelated Laravel application.

## Acceptance tests (automated where possible)
1. Newly created guardian can register and submit draft family request without existing official record.
2. Draft persists and restores to correct step; repeat submission cannot create duplicates.
3. One application with multiple children correctly reuses parent records but creates separate per-child relationships.
4. Mother-as-guardian reuses mother person identity and does not auto-verify legal guardianship.
5. New portal applicant cannot see official child records pending per-child approval; unauthorized child-ID manipulation returns 403/404 consistently.
6. Former guardian loses access when approved legal relationship expires or transfers.
7. Applicant can read own requests, statuses and requested corrections; cannot read another applicant's submissions.
8. Initial attachments optional and private; unauthorized medical document reads are rejected and audited.
9. All conditional education, father/mother status, housing/displacement and health validations apply server-side.
10. Original home address remains unchanged across displacement updates.
11. Official update uses reviewed change request and cannot silently overwrite canonical approved data.
12. Campaign nomination/pending score does not appear publicly, and no delivered benefit is displayed without validated delivery.
13. Initial campaign-list approval/amendment remains executive-director-only, unaffected by portal code.
14. Employee portal session and beneficiary session are correctly isolated.
15. Screens and messages are Arabic RTL and usable on small/mobile screens and intermittent connectivity.
16. Sensitive exports, caches, audit and error logs do not leak PII or medical documents.
17. Existing staff ERP, inventory, sponsorship, accounting and offline delivery behaviors continue to pass relevant regressions.

## Final report
When implementation is done (or at the current stopping point), report:
- repository architecture discovered and all assumptions corrected,
- file additions/changes, migrations executed (and not executed),
- functioning pages and workflows,
- verified RBAC/guard/policy boundaries,
- test commands and ACTUAL outputs,
- security considerations and unresolved gaps,
- local/deployment DNS, TLS, hostname and environment setup for `portal.al-amal.ps`, including no untested deployment claims,
- reproducible next steps.

**Do not introduce unrelated module redesigns, mock business data in production, automatic legal approvals, or misleading status labels. Implement a polished, safe beneficiary service portal integrated into the existing AIMS application.**
