# AIMS 2.0 — Smart Import Engine & Comprehensive Registry Data Model

## Role and mission
Act as Senior Laravel/PostgreSQL Engineer, Data Architect, Data Quality Engineer, and Arabic RTL UX Designer. Implement a production-ready, **intelligent and auditable** Excel/CSV import system in the **existing** AIMS application at `C:\Projects\al-amal-system`. This is a scoped upgrade to registry import, detailed orphan/family/guardian data fields, provisional approval, and safe relationship inference. Do not rebuild unrelated modules. All UI and validation messages must be Arabic, RTL.

## Non-negotiable project constraints
- Inspect actual application, Laravel version, existing PostgreSQL schema, existing Smart Import controllers/services, queues, models, permissions and tests before edits. Do not assume MySQL. Reuse shared `departments`, `locations`, people/parents/guardian/household/registry services and existing frontend stack.
- Keep AIMS's internal staff at `system.al-amal.ps` and beneficiary portal at `portal.al-amal.ps` as logically isolated interfaces inside the same Laravel modular monolith. Portal access to each child only after verified per-child legal authorization.
- No `migrate:fresh`, `db:wipe`, unapproved destructive changes, silent merging, or import-based activation of legal guardianship. Preserve historical records, audit logs, approved finances, inventory and campaign lists.
- The user wants to use **as much genuine source data as possible**, create *provisionally approved* operational registry records when safe, and avoid holding all records for missing optional fields. **Provisional is NOT legally verified, final approval, automatic sponsorship, campaign eligibility, or public portal access.**

## 1. Source workbook intelligence
- Work with uploaded workbook(s) when present; never assume a named workbook exists without checking. For the previously analyzed unified orphan workbook, inspect all sheets, including unified records, family reference sheets, guardians, review flags, duplicates and exclusions. Preserve sheet provenance and actual row number.
- Distinguish **primary rows** from supporting/reconciliation sheets. Do not import duplicates/excluded sheets as new children. Never blindly sum worksheet counts into unique people.
- Support XLSX and CSV; guarded XLS only if an existing safe parser supports it. Restrict size and MIME, prevent zip bombs, spreadsheet formula execution and CSV formula injection on exports.
- Build worksheet profiling: detected headers, sample types, nulls, suspicious patterns, field distribution, duplicate identifiers, cross-sheet contradictions, and source quality report.
- Intelligent **proposed** mapping for Arabic/English spelling variants and synonyms (e.g. رقم هوية الطفل, هوية اليتيم, Child ID). Show confidence and allow manual override. Never silently map ambiguous columns.
- Normalize names for matching without changing stored originals; national IDs are strings with leading zeros retained. Do not infer gender, father death, legal guardian authority, or orphan classification from a name alone. No made-up data.

## 2. Comprehensive registry fields to support
Use normalized relational entities with re-used persons rather than copying parents/guardian per orphan. Model nullable/unknown values, source, verification status and effective date where appropriate.

### Child / orphan
Four-part Arabic name (with separate components when reliably available, original full name always retained), national ID, DOB, calculated age, gender, birthplace, photo, orphanhood/eligibility classification and provenance, relationship to mother/father, education status/level/grade/school/type/performance/nonattendance reasons, health status/condition/disability type and documented severity/ongoing follow-up, social needs, external sponsorship (provider/type/period), file completeness/approval/verification states, real benefit history and linked guardian relationship.

### Father
Single shared person record, full name/ID/DOB, alive/deceased/missing/unknown status; death date/cause/evidence and verification, previous occupation/income sources/economic situation before death, dependents, source and confidence. Do not assume all orphans have a deceased father.

### Mother
Single shared person record, full name/ID/DOB, alive/deceased/missing/unknown status, marital status, phones, education and conditional specialization, occupation/employment/current income, health/social support needs, housing/displacement relationships, death metadata if applicable. Being a mother is not automatically equivalent to a legally verified guardian.

### Guardian
Shared identity record, four-part name, ID, DOB, phones, occupation, education, income, residence, verified legal proof, **one direct independent guardian-child relationship per child**, relationship type, source, `claimed/provisional/verified/rejected/ended` status, effective dates and guardian transfer history. A guardian may be linked to children across multiple households.

### Family / household
Household social/residential membership, total/male/female members, dependents, studying/employed counts, source-of-income categories, reported monthly income and socioeconomic situation, outside aid sources/periods, social assessment, original housing and current displacement status. Separate legal family kinship from household residence; household membership alone must not grant guardianship or portal permissions.

### Housing / displacement
Original and current addresses are separate historicized records: governorate, city, neighborhood, street, landmark, details, housing type, displacement status, dates, number of displacement events, occupancy and last verification.

### Attachments and provenance
Protected identity/birth/death/guardianship/medical documents, document owner, type, validity/review state, private storage, strict RBAC and audit. Documents optional at initial registration, may become required for verification.

## 3. Smart family and guardian matching
- Match in stages: exact validated IDs first, reliable relationship keys and cross-sheet reference IDs second, standardized names/address/phone only as supporting evidence. Show matches as confirmed, likely, ambiguous, conflicting, or unmatched with human-readable evidence.
- A **user-approved operational rule**: if one child in a clearly identified family has a guardian and siblings' guardian fields are missing, **propose** the same guardian for sibling children; automatically create **provisional** per-child relationship links only if family/parental evidence strongly supports the grouping and no contradictory guardian exists. Mark source `inferred_from_sibling_guardian`, reference source row/sibling/guardian, rationale and inference confidence. Otherwise hold for review.
- Do not group solely by one shared phone, street, guardian surname or a social household identifier. Explicit other guardians override group suggestions and create conflict if inconsistent.
- Link siblings to the same father and mother *person* records only if supported by IDs/evidence; never duplicate parent persons per child. Preserve unclear relationships as pending review.
- Do not infer that missing details mean zero income, no disability, not displaced or no sponsorship.

## 4. Provisional approval and risk-based workflow
- Separate registry state (`draft`, `provisionally_approved`, `pending_review`, `fully_approved`, `suspended`), data completeness, identity verification, guardianship verification, and portal account status. Existing states can be mapped after audit; avoid conflicting duplicate state columns.
- Low-risk omissions (e.g. missing school name, unspecified current housing type) should not necessarily block provisional records when mandatory identity and at least provisional direct guardian relationship requirements are satisfied.
- High-risk conflicts (different child IDs for same person, impossible DOBs, incompatible parent IDs, contradictory guardianship claims, prior approved file conflicts) must block provisional activation pending authorized human review.
- Provide a configurable, reviewable policy matrix defining minimal data for provisional acceptance, validation warnings, mandatory review and final verification. Keep hard safety/integrity invariants enforced server-side.
- Import runs in staging first, with human-controlled **batch provisional acceptance** and explicit report of what can be imported and what requires review. Do not make a bulk import act as final legal approval.
- Full record corrections must be audited, field-level and conflict-aware; avoid overwriting a verified existing value with unverified Excel content.

## 5. Fix the confirmed recurring importer bug BEFORE bulk import
The existing `app/Services/Orphans/Import/ImportPipeline.php` was observed attempting duplicate inserts for `(batch_id,row_no)` (batches 15 row 957, 16 row 680, 17 row 1658), violating `import_staged_rows_batch_id_row_no_unique`.
Observed code structure: `analyzeChunk()` reads a possibly stale `$batch->cursor` and calls `stage()` then separately increments cursor/rows_processed; `stage()` invokes `insertGetId()` without a safe existing-row path; `startAnalysis()` deletes staged rows when restarting. Inspect all callers, jobs, and schedulers and **prove root cause** before modifying.
- Preserve unique constraint, existing rows and manual decisions. Design per-batch concurrency serialization, fresh database state, atomic row/cursor advancement, safe idempotent retry and changed-hash conflict reporting. Do not blindly upsert over reviewed records.
- Make `startAnalysis` not destroy prior reviewed or approved staging without explicit authorized reset and audit.
- Ensure no cursor advances before a row's successful committed stage; robust row-level progress and exact processed/imported/reviewed counts.
- Resume existing failed batches only after auditing status, persisted rows, issues, and fingerprints, not by manually setting cursor without checks.
- Test concurrent workers, overlapping chunks, retry-after-crash, same-hash duplicates, different-hash conflicts, manual corrections, stale cursors, and interrupted Excel reads.

## 6. Arabic RTL Import Wizard UX
Keep visible «الاستيراد الذكي» action/icon as a shortcut within existing six department sections; **do not introduce a seventh main sidebar entry**. Icon concept: `file-spreadsheet` / `file-input` with subtle spark, aligned to existing icon library. Wizard steps:
1. «رفع الملف» — secure upload, workbook sheets, import purpose.
2. «مطابقة الأعمدة» — side-by-side source/target fields, intelligent suggestions with confidence and ambiguity warnings.
3. «تحليل البيانات» — unique persons, family clusters, sibling guardian proposals, missing fields, duplication and conflict analysis.
4. «المراجعة والتصحيح» — categorized staged records with per-field before/after diff, provenance, proposed actions and secure corrections.
5. «المعاينة والاعتماد المبدئي» — exact numbers for create/update/link/skip/review/reject; explicit authorized confirmation, never final legal guardianship.
6. «تنفيذ ومتابعة النتائج» — streaming/chunked/resumable jobs with progress, counts, safe errors/export, and restart/continue controls.
Filters and actions: «جاهز للاعتماد المبدئي», «يحتاج مراجعة», «تعارض», «مكرر», «ناقص بيانات», «مستورد». A side drawer shows person and family matches, linked orphans, inferred guardian, issue reasons, source sheet/row, and allowed resolution. Make mobile-friendly and usable on unreliable Gaza internet.

## 7. Import integrity and security
- Source snapshot/provenance with minimal necessary sensitive data, encryption and retention; masks in UI and exports. Health data access separately permissioned.
- Database constraints: unique appropriate identity keys and per-batch row IDs, strict foreign keys, unambiguous relationship validity, transaction boundaries, audit trails; safe batch retries with idempotency keys.
- Do not expose confidential data to portal accounts on provisional links. Never activate portal child access from imported claims alone.
- Do not modify approved campaign rosters with imported spreadsheets without **executive director exclusive approval** for both original list and changes. Import alone is not proof of aid delivery.
- No dummy production results; all dashboards and report counts must be derived from real stored records.

## 8. Detailed tests and acceptance criteria
Demonstrate: parsing multiple sheets without duplicate import; ID preservation; families reconstructed with correct siblings; shared parent identities; one provisional guardian person with per-child links; no silent inference on ambiguous families; missing noncritical data allowed for provisional intake; hard conflicts blocked; changed existing verified values not overwritten; confidential health details protected; correct status transitions; no access to children through portal until verified; 10k–60k row benchmark with bounded memory and resumable progress; robust staging under concurrent workers; error details; accurate post-import report. Run migrations/tests on safe existing environment, no destructive resets.

## 9. Execution and outputs
1. Inspect actual repo and data model; summarize discrepancies and implementation plan.
2. Repair existing importer concurrency/idempotency defects and add regression tests.
3. Implement missing detailed profile fields through safe additive migrations and dynamic reference settings (never hard-coded taxonomy if already configurable).
4. Implement evidence-based family matching, sibling guardian proposals, provenance and provisional approval.
5. Upgrade Arabic RTL import wizard, background processing, detailed conflict reviews and data-quality reporting.
6. Perform integration/security/performance tests and verify non-regression for AIMS departments, beneficiaries portal, campaigns, financial/inventory data.
7. Provide exact files changed, schema relationships, applied migrations, executed test results, limits, and safe steps to import the unified workbook.

**Do the actual code changes, not just a design report. Do not claim an import succeeded unless actual persistent outcomes were verified.**

---

## 10. FINAL APPROVED REQUIREMENT — WRITE PROVISIONAL RECORDS TO THE REAL AIMS REGISTRY (MANDATORY)

**Clarification overriding any earlier ambiguous staging-only interpretation:** The user explicitly requires eligible imported orphan, guardian, parent and family records to **become real, queryable records in the existing operational AIMS registry tables** with the independent status **«معتمد مبدئيًا»**. They must not remain exclusively in `import_staged_rows` once the authorized provisional import action succeeds. Staging is a temporary validation and review area, not the final destination.

### Operational commit requirements

1. Keep the smart analysis/preview/staging workflow, then expose the explicit server-authorized **«اعتماد مبدئي وإدراج في السجل»** action for eligible rows and family clusters. A simulation/preview must NEVER mutate official registry data.
2. On commit, use the existing identity/person registry and PostgreSQL tables/services; create or safely link one family/household and shared father/mother/person records, a guardian person record, each individual orphan's official registry record, and a **distinct guardian-to-orphan relationship per child**. Never create a parallel orphan/guardian master registry merely for importing.
3. Each successfully committed orphan must be searchable from **«إدارة الأيتام والأوصياء»**, visible in permitted family/guardian profiles and provisional-filtered reports, and carry `provisional` registration status and explicit source/provenance. The imported provisional status must remain distinct from completeness, review/verification, orphanhood classification, portal-account activation and legal-guardianship verification.
4. If one reliably identified family has one known guardian and several orphan siblings with missing guardians, propose/reuse that guardian across the other children; create **separate pending-verification claims** for each relation after validating group identity and detecting conflicts. A shared social household by itself is insufficient to establish legal guardianship. If relationships are ambiguous or conflicting, route only the affected records/relationships for review, rather than mis-linking children.
5. **Per-child precondition:** Do not commit an operational orphan without at least one directly linked guardian relationship, which may be provisionally associated/pending legal verification. Keep unresolved orphan rows staged as actionable review cases. Do not infer guardian access rights, assign sponsorship, nominate a campaign winner, or recognize actual aid delivery from provisional status.
6. Preserve every available source field and its provenance; mark unprovided optional values missing rather than fabricate defaults. Do not overwrite already verified identities/guardianships or manually corrected entries with Excel values. Updates must show a field-level diff and require appropriate authorization.
7. Execute family-person-child-guardian linkage as transactional, referentially consistent **units of work** (per validated family cluster or controlled smaller unit with explicit rollback boundaries). Use stable source row/batch keys, unique constraints and idempotency to ensure retries never duplicate people, families, relationships, benefits or audit events.
8. Link each staging row to the official records actually created or matched via import-result references (existing link table or safe additive one), record `created`, `linked`, `updated`, `skipped`, `held_for_review`, `failed` individually, and commit these results atomically with operational changes where possible.
9. Do not mark the whole batch `completed` merely because analysis finished; distinguish analyzed/staged, provisionally imported, partially imported, requires review, and failed. Output counts for **source rows vs unique children vs unique guardians vs unique families** to prevent misleading totals.
10. The administrative screen must display **«معتمد مبدئيًا»** visibly for committed records, plus why an item could not be committed and a direct action to complete missing guardian association or resolve conflicts.
11. Keep imported provisional records out of default final-approved-only statistics and privileges unless the UI explicitly identifies provisional vs final figures. Never expose them in `portal.al-amal.ps` solely on source Excel evidence.
12. Respect real approval rights for source record onboarding and all existing Executive Director campaign-list approval protections. Do not use this batch action to approve a campaign roster or change one already approved.

### Required acceptance proof

Run a safe, small, real-file representative test after fixing the recurring `SQLSTATE[23505]` defect. Confirm actual database rows, real UI search results, shared father/mother/guardian reuse, correct per-child pending guardian links, provisional status and provenance; demonstrate that repeating the exact import produces **zero duplicate official records**. Demonstrate that orphan rows with unresolved guardians remain staged rather than being force-committed. Verify role-scoped portal denial and that finalized financial, campaign and inventory operations are unchanged. After verification, process large batches with chunked/resumable accounting and reconcile source-to-official totals. Report actual test outcomes, never assumed outcomes.
