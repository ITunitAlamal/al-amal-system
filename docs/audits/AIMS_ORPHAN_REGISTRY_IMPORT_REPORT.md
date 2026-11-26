# Orphan registry import — «جميع المستفيدين» (2026-10-30)

**Was `al_amal_db` modified? YES, staging only.** Owner's choice: "migrate without backup".
- Migration `2026_11_27_000001_orphan_registry_import_staging` ran as batch 99: 4 new tables and 2 menu entries.
- One batch was uploaded and analysed: status `analyzed`, **0 staged rows**.
- Approved registry tables are untouched: `orphan_children`, `orphan_households`, `orphan_guardianships` and `sponsorships` all still hold 0 rows; `guardians` still holds its 1 pre-existing row.

## 1. Repository audit (what exists)

| Feature | Existing | Change | Migration |
|---|---|---|---|
| Approved tier | `orphan_children`, `orphan_parents`, `orphan_households`, `orphan_household_parents`, `orphan_guardianships`, `orphan_documents` (all 0 rows), `OrphanRegistry`, `RegistryIdentity::hash` (HMAC) | reused read-only for matching (`existing_record`); never written | N |
| Access | `OrphanAccess` capabilities via `orphan_access_grants`: the head requests, «مدير النظام» implements; head, admin and ED rules | new capability `imports` («استيراد الكشوف وتدقيق دفعاتها»); `view`, `review`, `verify` and `sensitive` reused | N |
| Reference data | «ثوابت النظام» `master_data_*`: governorates (10), kinship_types (10), education_levels (7), marital_statuses (4), education_stages (5); `health_statuses`, `death_causes`, `academic_grades` are empty | aliases table maps source variants → master values; no values invented | Y |
| Staged-import pattern | `employee_import_batches/rows/aliases` (HR, first sheet only, 5 000-row cap) | same pattern, orphan-specific tables; new streaming reader (named sheet, no row cap) | Y |
| Audit | `AuditService::recordRow` → `audit_logs`; `orphan_access_log` for reads | reused (upload, analysis, approval, staging, decisions, issues, aliases, exports, purge; row reads) | N |
| Workflow engine | ORPHAN_FILE_ACTIVATION etc. | not used: owner decision 2026-10-22 keeps registration paused until the civil-registry API exists, so **no promotion path to the approved tier was built** | N |
| Background work | `QUEUE_CONNECTION=database` with no worker; Windows scheduler runs `schedule:run` | `orphans:registry-import` every minute (`withoutOverlapping`); batch claimed atomically; resumable | N |

## 2. Source profile (one sheet only, counts only)

The workbook contains a single sheet. Only «جميع المستفيدين» is opened (`StreamingXlsxReader` refuses any other name). Streaming a full pass takes ~30 s at 49 MB peak; analysis is 2 passes, ~70–100 s at 179 MB.

| Metric | Baseline | Recomputed | Note |
|---|---|---|---|
| Data rows / columns | 48 518 / 41 | 48 518 / 41 mapped, 0 missing, 0 moved, 0 unknown | |
| 9-digit child-ID rows / distinct / repeated extra rows | 47 591 / 47 589 / 2 | same | 0 repeated IDs with conflicting data |
| Non-9-digit child IDs | 927 | 927 = 4 empty + **507 eight-digit numeric** + 103 short + 305 long + 8 non-digit | numeric cells drop a leading zero; flagged, never padded |
| Guardian IDs / households (father+mother) / guardians in >1 household / households with >1 guardian | 19 233 / 19 792 / 1 119 / 495 | same | |
| Both parent IDs valid | 47 775 | 47 775 | |
| Sex populated / missing | 665 / 47 853 | 632 male + 33 female / 47 853 | never inferred |
| «الأم» claimed with a different guardian ID | 1 079 | 1 081 | compared on raw IDs; my mother set adds «امهم»/«امي» |
| Guardian equals father ID | 315 | 315 | |
| Guardian-name variants | 3 005 | 3 007 (whitespace only), 3 057 raw; **1 953 after Arabic normalisation** | the review rule uses the normalised form; raw variants shown |
| Family total ≠ males + females | 18 066 | 18 064 (integer cells), 107 non-numeric counts flagged separately | blank semantics |
| Father death > 300 days before birth / after registration / future births | 313 / 380 / 4 | 313 / 381 / 4 | one boundary row |
| Governorate variants | «الوسطى/الوسطي» | 4 keys after normalisation (2 «الوسطي» rows merge) | source text kept |
| Text dates (not Excel dates) | — | ~200 cells: d/m/Y, 189 ambiguous or month-first, 8 unreadable | each flagged |

## 3. Field map (all 41 columns, `RegistrySheet::COLUMNS`)

Headers are matched by their normalised label, with the documented letter as a cross-check; duplicate or missing headers block the import.
- **Protected (stored encrypted, shown only with `sensitive`):** E photo link (never fetched), K/L health, Y/Z phones, AK address, AM displacement address, AN/AO extras.
- **IDs (H, N, T, AG):** kept as text with status `format_ok | missing | short | short_numeric | long | not_digits`.
- **G «ألعمر»:** kept as a source snapshot; age is always computed from F.
- **I «الصف الدراسي» and J «المستوي الدراسي»:** separate fields.
- **AF/AG/AH:** stored as a *claim*.
- **AN «العمود 1»:** 4 values kept. **AO «العمود 2»:** empty, acknowledged.

## 4. Tables (`2026_11_27_000001`, additive; rollback refuses while staged data exists)

- `orphan_import_batches`:
  - unique `file_sha256`;
  - CHECK `source_sheet = 'جميع المستفيدين'`;
  - CHECK approver ≠ uploader;
  - status machine `uploaded→analyzing→analyzed|blocked→approved→staging→staged|failed|cancelled`;
  - `cursor_row` for resume;
  - `supersedes_id` for revised files.
- `orphan_import_rows`:
  - unique (batch, Excel row);
  - `raw_payload` holds all 41 values, encrypted;
  - trigger makes evidence immutable;
  - normalised search and filter columns;
  - `child_key`, `household_key` (father|mother), `guardian_key`;
  - `review_status`;
  - **three separate decisions** (identity / household / guardianship: `supported | rejected | needs_evidence`, evidence note ≥ 10 characters enforced by CHECK).
- `orphan_import_issues`:
  - unique (row, type, reason);
  - severity, status, assignee;
  - resolution requires a note and a resolver (CHECK).
- `orphan_import_aliases`: unique (field, source variant) → `master_data_values`.

## 5. Matching and grouping

- **Strong child candidate:** a 9-digit child ID only. A repeated ID whose DOB, name or parents differ raises `child_conflict`; otherwise `duplicate`. Nothing is merged.
- **Provisional household:** both parent IDs valid. Never grouped by father or guardian alone; a father with two mothers gives two households.
- **Claimed guardian:** grouped by its 9-digit ID, with name variants, households and children per guardian. One claim per child row.
- **Statuses shown separately:**
  - personal data, household membership and the claimed guardian: «بانتظار التأكيد» until a decision is recorded;
  - legal guardianship: always «بانتظار التحقق من الوصاية»;
  - portal: «غير ممنوح».

## 6. Review rules (`RegistryIssueTypes`)

high: مطابقة لملف معتمد قائم، هوية الطفل غير مكتملة، هوية الطفل غير مطابقة للصيغة، تعارض بيانات الطفل، تعارض هوية الوصي والأم، الوصي مطابق لهوية الأب · medium: تاريخ يحتاج مراجعة (future birth / death long before birth = high)، تكرار محتمل، تعارض ربط الأسرة (child in two households = high)، تعدد أوصياء محتمل، اختلاف اسم الوصي، الوصاية غير مؤكدة · low: بيانات الأسرة تحتاج تفسيرًا · info: الجنس غير محدد.

Expected from the dry run: 2 501 rows with a high-priority issue, 8 632 medium, 13 622 low, 23 501 info only, 262 with no issue. The inbox hides the info category by default. Details hold reason codes only, never values.

Bulk actions are limited to: assign a reviewer, mark for follow-up, map an alias, approve a batch to staging. There is no bulk confirmation.

## 7. Screens and routes (`routes/aims_orphans.php`, all under `ProtectOrphanResponses`)

- «استيراد الكشوف» `/orphans/imports` (capability `imports`):
  - private upload (≤ 50 MB, duplicate hash refused);
  - dry-run report (41-column map with fill counts, reconciled counts with their denominators, expected issues);
  - approval by a second person, with a note and an acknowledgement;
  - progress, resume and cancel.
- «تدقيق البيانات» `/orphans/staging` (`view`), with these tabs:
  - «لوحة شؤون الأيتام»: counters with stated denominators;
  - «الأيتام»: filters (name prefix, exact ID for `sensitive` only, sex including «غير محدد», age computed from DOB, displacement, governorate, review status, issue type, ID format);
  - «الأسر»;
  - «الأوصياء»;
  - «شجرة العلاقات»: الوصي ← الأسر ← الأيتام, plus the household-centric view;
  - «التصنيفات»: rows vs distinct children vs reviewed, aliases to «ثوابت النظام»;
  - «تدقيق البيانات»: prioritised inbox, bulk assign, resolve, dismiss, follow-up.
- «ملف اليتيم» of a staged row has 10 tabs, compares rows sharing the same ID, and records the three separate decisions.
- Exports (Excel / PDF / CSV / طباعة) need `review`, mask IDs by default (full IDs only with `sensitive` plus an explicit choice), stop above 20 000 rows, neutralise formula injection, and are audited.
- Every row read is logged in `orphan_access_log`.

Files:
- `app/Support/Spreadsheets/StreamingXlsxReader.php`;
- `app/Services/Orphans/Import/{RegistrySheet,ArabicText,RegistryRowNormalizer,RegistryIssueTypes,RegistryAnalyzer,RegistryImports,RegistryStaging}.php`;
- `app/Http/Controllers/Orphans/RegistryImportController.php`;
- `app/Console/Commands/OrphansRegistryImport.php`;
- `resources/js/Pages/Orphans/{Imports,Staging}.vue`;
- edits to `OrphanAccess` (capability), `NavigationRegistry` / `NavigationAccessRules` (2 entries, rule `orphans_imports`), `routes/console.php` (schedule).

## 8. Verification (real system, text only)

No PHPUnit files or fixtures were created: the owner's standing rule forbids test files and sample data. Verification ran on the real system with rolled-back transactions and restored sequences.

| Check | Result |
|---|---|
| Dry run (in memory) vs baseline | reconciled (table §2) |
| DDL rehearsal: other sheet name, approver = uploader, unknown status, duplicate row, rewriting evidence, decision without note, invalid sex, resolving without note | all refused by the DB |
| Real upload | stored under `storage/app/private/orphan_imports`; source file hash unchanged; analysis `analyzed`, 0 blockers; batch JSON holds no 9-digit IDs |
| Uploader approves own batch | 422 |
| Unauthorised user (u21) | 403 on batches, lists and row file |
| Rehearsal: 1 000 real rows staged then rolled back | 1 000 rows / 1 666 issues; a retry of the first 500 created 0 duplicates; payload encrypted |
| All read endpoints on the rehearsal | 200 in 21–209 ms (dashboard, filtered children, name search, households (+multi-guardian), guardians (+multi-household), tree, household view, categories incl. health, inbox) |
| Decision with a short note / with evidence | 422 / 200; still 0 guardianships and the 1 pre-existing guardian |
| CSV / XLSX export | 200; IDs masked; no 9-digit IDs present |
| Bug found and fixed | plain `throttle:x,1` shares one counter per user across routes → exports returned 429; the new routes now use prefixed limiters |
| `npm run build`, `pint` | passed |

Not exercised:
- the full 48 518-row staging (it waits for the second-person approval);
- a browser upload through the page;
- a 50k-row timing on the real tables (the rehearsal measured 1 000 rows; full staging is estimated at a few minutes).

## 9. Next steps / operator decisions

1. **Approve the staging import:**
   - who: the orphans head (user 29) or another holder of `imports`, but not the uploader (admin #1);
   - where: «استيراد الكشوف» → «اعتماد الاستيراد المؤقت»;
   - then: the scheduler stages the batch within minutes, or run `php artisan orphans:registry-import` to start it at once.
2. **Grant capabilities to reviewers:** `review`, `verify` and, only where needed, `sensitive` (head requests, «مدير النظام» implements). The department still has no staff.
3. **Fill the empty «ثوابت النظام» lists** (health_statuses, death_causes, academic_grades), then map variants in «التصنيفات».
4. **Pending decisions:** retention period for staged rows and the uploaded file; who confirms identities and legal guardianship; the civil-registry API (promotion to the approved tier stays blocked until then); the eligibility age (18 still pending).
5. **Rollback:** `php artisan orphans:registry-import --purge=<public_id> --admin=1 --reason="…"` removes only that batch's staged rows. It is refused once any decision has been recorded, and it is audited.
