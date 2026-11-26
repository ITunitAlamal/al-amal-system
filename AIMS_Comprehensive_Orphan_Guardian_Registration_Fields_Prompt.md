# AIMS 2.0 — Comprehensive Orphan, Guardian & Family Registration Fields

## Role and scope
Act as a Senior Laravel/PostgreSQL Architect, Business Analyst, Accessibility-focused Arabic RTL UI/UX Designer, and QA Engineer. Implement **only the comprehensive registration data fields, forms, data model extensions, and validation** for orphans, guardians, family, father, and mother within the **existing** AIMS Laravel application. This is a production implementation task, not a mockup. Do not rebuild campaigns, dashboards, financial systems, or unrelated modules.

**Inspect first:** Laravel/PHP versions, actual frontend stack (Vue/Blade/Bootstrap/etc.), PostgreSQL tables, registration services, beneficiary portal flow, permission/audit system, existing field definitions, and data constraints. Reuse and extend existing models/tables/services. Provide a concise audit and phased change plan before modifying files. Do not assume MySQL; AIMS uses PostgreSQL. Preserve live records and existing functionality; never run `migrate:fresh`, `db:wipe`, TRUNCATE, or destructive schema resets.

**Language and UX:** All user-visible labels, form messages, headings, buttons, tooltips, review states and notifications must be Arabic with RTL layout. Respect Al-Amal branding and existing components. Design mobile-first, light, accessible, fast on low bandwidth. Support partial draft saving and resuming. Do not add new top-level sidebar entries.

## Core domain rules (mandatory)
1. A **family-based registration request** may include several orphans; the operational orphan profile belongs to an individual child and each child must have a **direct guardian relationship**, at least pending verification. A guardian can exist without registered orphans and can be linked to children from different households.
2. Family/household membership represents social/residential reality, **not** legal guardianship or portal access. Portal access to each child's official records is granted only after that particular guardianship relationship is verified and approved.
3. Mother, father, guardian and child identities must be reusable person records when verified; never duplicate the mother when she is guardian; never duplicate deceased parents for siblings.
4. Father's status may be deceased, missing, alive, or unknown. Mother's status likewise. Never assume all orphans are fatherless. Classification follows configurable approved policy and evidence.
5. Keep separate statuses for request workflow, individual profile completion, official record review, guardianship verification, portal account, and sponsorship. Documents are optional on first submission unless an approved policy explicitly makes a particular document mandatory at a later approval step.
6. Preserve existing verified data and review history; guardian/parent/civil-ID matches imported from Excel are **unverified claims**, not automatic legal proof. Medical and socioeconomic data are restricted, encrypted/protected where appropriate, and not broadly exposed to the beneficiary portal.
7. Fields and reference lists must be configurable where possible through the existing **«ثوابت النظام»**, while legal/security constraints remain enforced server-side. Do not hardcode one governorate, orphan age limit, or school stage in application code.

## Arabic wizard structure
Create a well-designed registration wizard with progress indicators, validation per step, previous/next, **«حفظ كمسودة»**, **«إضافة يتيم آخر»**, review summary, and final declaration. Authorized employee entry and beneficiary self-registration should reuse domain rules while applying different policies and display scope.

### Step 1 — «بيانات طلب التسجيل»
- Auto-generated request number, created/submitted timestamps, source (بوابة المستفيدين / موظف المعهد), applicant full name, applicant role/relationship to the family, primary and alternative mobile numbers, optional email, preferred contact channel, notes, and assigned employee if entered internally.
- Clearly distinguish applicant identity from legal guardian identity; a requester is not automatically entitled to child access.

### Step 2 — «بيانات الأب»
- Four distinct name fields: «الاسم الأول»، «اسم الأب»، «اسم الجد»، «اسم العائلة», full name display; national identity number stored as a **string**, birth date, birthplace (optional), nationality if allowed by policy.
- Father status: «على قيد الحياة»، «متوفى»، «مفقود»، «غير معروف»; relevant conditional dates, place, declared cause of death, official cause/evidence and source/confidence independently; missing date, last known location, missing status/details when applicable.
- Occupation and employment type before death/disappearance, employer/work sector (optional), last known income band/estimated monthly income, main household financial contribution, income interruption after loss, benefit/pension/compensation information only if known and appropriate.
- Avoid treating pre-death wealth as a proxy for current family need; label unverified fields and allow «غير معروف».

### Step 3 — «بيانات الأم»
- Four-part name, ID string, date of birth, status alive/deceased/missing/unknown; date/cause of death or missing details when applicable, phones and means of contact.
- Marital status and date of change when relevant, education level (أمية، ابتدائي، إعدادي، ثانوي، دبلوم، بكالوريوس، ماجستير، دكتوراه، أخرى), university/major conditional on relevant qualifications.
- Occupation, employed yes/no, work sector, stability, monthly income band/amount if known, ability to care for children and dependents, current address if different from household.
- Relevant health/support needs only under appropriately protected fields; never equate maternal relationship with verified legal guardianship.

### Step 4 — «بيانات الأسرة والوضع الاجتماعي والاقتصادي»
- Family/household identifier, household contact, total residents, males, females, number of registered orphans auto-calculated, dependents, currently studying, employed, people with special support needs (summary only), household head (social role), relationship to child.
- Configurable income sources (multiple): wages, occasional work, pensions, savings, assistance, remittances, none, other; total approximate monthly income with currency and as-of date; major expenses and rent if appropriate; employment stability; economic status (جيد، متوسط، ضعيف، صعب جدًا) as reported vs assessed distinctly.
- External organizations and assistance types, dates and frequency, but do not equate self-reported assistance to verified AIMS delivery events. Distinguish family-level aid from child-specific aid; do not multiply household aid across siblings.
- Derived counts must be validated against available household-members data, with clear definition of whether parents/guardian/absent or displaced members are counted; ensure male + female = total when those counts are requested.

### Step 5 — «بيانات الوصي وعلاقة الوصاية»
- «هل الوصي هو الأم؟» yes/no. If yes, link mother person record, do not copy it. Otherwise collect separate four-part name, ID string, DOB, contact numbers, optional email, gender, education, occupation, employment, income and current address.
- Relationship type to **each orphan**: mother, father, grandfather, grandmother, paternal uncle/aunt, maternal uncle/aunt, brother, sister, other relative, other; relation taxonomy in configurable constants.
- Legal authority type, document references, date granted/validity if any, issuing body, verification status («مدعاة»، «قيد التحقق»، «معتمدة»، «منتهية»، «مرفوضة»), reviewer, reason and validity window. Entering a document is not auto-verification.
- Support separate guardians for different children within the same registration request and historical guardian changes; never unlink the last guardian without a safe documented replacement/exception workflow. Changes must revoke/re-evaluate portal permissions.

### Step 6 — «السكن الأصلي والنزوح»
- Separate original permanent address and current address with governorate > city/area > neighborhood configurable cascading selects; street, landmark/nearest mosque, optional coordinates only if policy allows, detailed address.
- Housing tenure: owned, rented, relatives, hosted, camp, shelter, other. Displacement status, current address, displacement date(s), number of moves, last movement date, host family/temporary accommodation, rent burden, shelter stability, accessible housing needs and verification/source.
- Preserve original address history and change records. Do not overwrite original address on relocation. Support guardian and mother address differences without falsely assuming co-residence.

### Step 7 — «الأيتام» (repeatable child cards)
For each orphan independently:
- **Identity:** four distinct name inputs + assembled display name, national ID string, sex/gender as allowed by institutional policy, birth date and computed age (read-only, date-sensitive), place of birth, optional photo, mother/father links, household affiliation, direct linked guardian(s), orphan classification with evidence and override/review explanation.
- **Education:** stage, enrollment status (ملتحق، روضة، جامعي، منقطع، غير ملتحق، دون سن الدراسة), grade, school/university, education type, academic performance, attendance, reason for dropout/non-enrollment, learning accessibility/support, last update date. Dynamic educational stages.
- **Health:** broad health status, diagnosed chronic conditions/temporary conditions if supplied, disability category/severity only if documented, regular follow-up requirement, treatment/support needs, referral documents, last assessment, verification and privacy visibility. Do not require diagnosis or upload to submit initial registration.
- **Social and economic:** special needs, household context and dependency, loss-of-parent circumstances (optional and safe), existing sponsorship elsewhere with organization/type/start-end if known, special educational/material needs, researcher observations (internal only).
- **Sponsorship:** external reported sponsorship separately from AIMS' verified sponsorship ledger; never create finance or assistance deliveries from self-reported values.
- Add/edit/remove child cards in draft with confirmations. Approved records are archived/modified via controlled workflows, not ordinary delete.

### Step 8 — «المرفقات والمستندات»
- Optional initial attachments: child national ID, birth certificate, father/mother death or missing evidence, parent/guardian IDs, guardianship evidence, school proof, medical reports, displacement/shelter documentation, personal photo and other.
- Attach each to the correct entity (person, family, guardianship relationship, or request), with document category, submitted date, verification status and reviewer.
- Secure private disk, controlled authorization, PDF/JPG/JPEG/PNG support, MIME/content/size checks, malware scanning where infrastructure supports, safe image processing, file retention controls, download audit. No public URLs.

### Step 9 — «مراجعة البيانات والإقرار»
- Consolidated summary organized by family, parents, guardian(s), each orphan, addresses, documents and missing items. Enable returning to relevant step without losing data.
- Mandatory truthfulness declaration and consent/notice to process and verify data, subject to approved institutional privacy language; applicant name/date; submit button and post-submit tracking number.

## Conditional behavior and validation
- Required when relevant: death date/cause only where recorded as deceased and policy requires; missing-person fields only for missing statuses; university specialization only for applicable education; current displacement address only when displaced; educational non-attendance reason only when not attending; medical details only when relevant and voluntarily provided; other-organization details only if assistance/sponsorship is indicated.
- Never turn unknown/withheld/undocumented values into invented facts. Provide explicit «غير معروف» / «لم يتم التحقق» as appropriate.
- Verify ID syntax using **configurable** local policy and uniqueness checks that distinguish exact identity, duplicate claim, and genuinely shared guardians. Preserve leading zeros; normalize Arabic-Indic numerals for comparison.
- Prevent future DOB and impossible birth/death chronology; make age calculated not entered; validate family size and child count without assuming all members are orphans.
- Use Laravel FormRequest / domain validation on the server, and parallel friendly inline Arabic messages in the frontend. Multi-step draft validations may be less strict than submission and approval policies.
- Staged imports, new portal applications and staff-created drafts all go through the same identity matching and approval rules, without importing directly into official verified records.

## Workflow, authorization and preservation
- Draft / submitted / under review / requires completion / approved / rejected requests; allow per-child and per-guardianship review states so one family request does not imply blanket approval of every child.
- Approval and attachment verification must be separate. Registration approval must resolve or reuse official identities and create stable relationships transactionally, without duplicate parents, orphans or guardians.
- Role-scoped access: beneficiary views their own applications and only child records with verified current legal authority; staff roles use existing AIMS permissions; medical/economic information, exports and attachments are further restricted. Audit all material changes. Prevent IDOR and mass assignment.
- Keep all prior data, historical relationships, sponsorships, aid campaigns, executive-director-only campaign list approval and other modules untouched; this task is limited to registration data/forms.

## Deliverables and tests
- Inspect and report actual schema; proposed field dictionary mapping to existing vs new columns, normalized entities and relations, data migration plan; execute additive safe migrations in PostgreSQL.
- Implement responsive Arabic RTL 9-step form, reusable dependent field components, repeatable child cards, guardian reuse, auto-save drafts, document handling, review summary, backend validation and permission-aware staff/portal workflows.
- Add automated tests for siblings sharing parents, mother-as-guardian without duplicating people, different guardians per child, legal relationship vs household membership, missing guardian preventing activation, conditional validation, draft resume, ID duplicates, sensitive-data access, private attachments and review/approval transitions.
- Run targeted tests, frontend build and relevant checks. Report actual results and limitations; never claim completion without verification.

**Start by inspecting the repository, then implement in controlled phases. Do not just write a plan or mockups.**
