# Orphans UX V2 — first clutter-reduction batch

## Plan and scope

Continue the existing [route/data/access audit](orphans-ux-audit.md), then reduce visible controls across the module without adding a second sidebar, routes, tables, dependencies, permissions or decision rules. Keep the existing Arabic leaf labels and workflow endpoints. This batch changes presentation and result-context handling; it does not complete the full V2 programme.

## Before / after inventory

Numbers below come from the current templates and NavigationRegistry definitions. They are not measurements of live staff accounts. Permissions and administrator menu configuration determine actual visible leaf counts.

| Surface | Before this V2 batch | After |
| --- | --- | --- |
| Global department navigation | One `الأيتام والأوصياء` group; nine registered leaves, each with an icon | Same single group and nine registered destinations; department leaf links are text-only; primary group keeps its icon |
| Registry workspace | Nine queue links in three navigation cards, each link with an icon | One labeled selector containing the same available queues; no card-grid navigation or queue icons |
| Registry header, fully capable fixture | Four simultaneous shortcuts/actions, all with icons | One create action and `المزيد`; scoring/plans remain inside it; duplicated sponsorship shortcut removed |
| Registry statistics | Four cards before the list | Department totals available inside `إحصاءات القسم`, collapsed by default; filtered count remains beside results |
| Child | Up to six primary section buttons | Four primary sections; education, protected health, score, documents and change requests retain their leaf labels inside disclosures |
| Household | Seven simultaneous section buttons | One labeled selector; all seven sections retained |
| Sponsorship workspace | Up to eight simultaneous section buttons | One labeled selector; existing nomination and edit flows retained |
| Relief campaign | Seven simultaneous section buttons | One labeled selector; approval, roster, exceptions, assignments, distribution and reconciliation remain reachable |
| Staging | Six section buttons and a second ten-button detail bar | Two contextual labeled selectors, no nested tab bars or navigation icons |
| Staging search | Separate name/ID fields and all advanced fields displayed | One search entry; advanced criteria collapsed in the shared toolbar |
| Staging dashboard | Eight equally prominent KPI cards | Four primary cards; four secondary statistics in a disclosure |
| Staging export | Four visible format buttons plus unmask checkbox | One `تصدير` disclosure; real existing formats and unmask choice inside |

Role interpretation: department head/admin see their server-authorized navigation; record staff see the same authorized destinations without decorative leaf icons; sponsorship officers use the existing sponsorship capability and workspace; campaign-only delegates keep campaign-scoped routes and gain no record access. No per-role menu totals were inferred from roles alone because explicit grants, appointments and configured menus also matter. For any role with N visible department leaf links, those N decorative leaf icons become zero; links and permission checks remain unchanged.

## Implemented operator flow

- **Find/filter:** ListToolbar is shared by registry, sponsorships and staging. Registry and sponsorship chips remove individual applied conditions. Staging uses a nine-digit exact-ID query only when the existing sensitive-data capability permits it; otherwise the field submits the existing name criterion. The registry label accurately describes its current reference/child-ID search, rather than claiming name search it cannot perform.
- **Review:** registry rows reuse RecordPreview with fields already present in the authorized list payload. No extra child identity, document or sensitive-data endpoint was introduced. The guardian field explicitly says it is not proof of legal authority.
- **Edit:** existing household, education, health and change-request forms remain. Child summary links to documents/change requests; changes still submit the existing workflows. No automatic approval action exists in this batch.
- **Return:** a child/household return URL retains allowed filters, page and the selected household anchor. No return state is stored globally across users. External destinations, arbitrary fragments and unknown query parameters are rejected or discarded.
- **Report/export:** staging exports now use the last successful applied query, not unsubmitted draft inputs. Masking defaults, confirmation and existing backend export routes remain. Late search responses cannot overwrite newer result/export context.

## Task-flow comparison

| Task | Before | After / qualification |
| --- | --- | --- |
| Household list quick review | Open full household page | One preview action, then close to unchanged results |
| Child → linked household | One action | One action; return context retained |
| Child → displayed guardian relationships | In child data section | In child summary; household people section is two actions away |
| Return from child after household traversal | Explicit filtered return unavailable in original UI; first batch kept filters/page | One return action with filters/page and selected row anchor |
| Open child education form | One section action | Social section + education disclosure: two actions; fewer primary choices, no claim of fewer clicks |
| Staging advanced condition | Every criterion displayed | Open advanced filters, set condition, apply |
| Export a known format | One format action + existing confirmation | Open one export disclosure, choose format + same confirmation |

The requested unified child/guardian/sponsor search and competing-claims explorer are still backend gaps; this batch does not claim that a child can be found by normalized name in the approved registry.

## Files and reuse

Modified:

- `resources/js/Layouts/AppLayout.vue`: department submenu icon budget only.
- `resources/js/Pages/Orphans/Index.vue`: compact queue selection, shared filter toolbar, safe list preview, existing mobile-card table pattern, row return anchors and compact header actions.
- `resources/js/Pages/Orphans/Child.vue`: four primary sections, progressive disclosure and honest missing birthdate/age display.
- `resources/js/Pages/Orphans/Household.vue`, `Sponsorships.vue`: compact section selection; sponsorship toolbar/chips.
- `resources/js/Pages/Orphans/Staging.vue`: selectors, unified search entry, advanced filters, applied-query exports, race protection, restrained KPI layout.
- `resources/js/Pages/Relief/Campaign.vue`: compact campaign section selection.
- `resources/js/Components/Orphans/RecordContext.vue`, `resources/js/Support/orphanNavigation.js`: text navigation and safe row anchors.

Added:

- `resources/js/Components/Orphans/{SectionSelect,ListToolbar,ActionDisclosure}.vue`.
- `tests/orphanWorkspace.test.mjs`, `tests/orphanResultsPosition.test.mjs`.
- `tests/Fixtures/renderOrphanWorkspacePreview.mjs`: generates static synthetic previews from actual Vue templates, without booting the app or reading a database.
- This report, `orphans-ux-preview.html`, and synthetic desktop/mobile PNG previews.

Reused AppLayout, PageHeader, StatusBadge, ActiveFilters, RecordPreview, existing `aims-responsive-table` styling, and the installed Vue compiler/server renderer. Existing routes and Arabic leaf labels remain functional, so no redirects or migrations are necessary.

## Validation and previews

`node --test tests/orphanWorkspace.test.mjs tests/orphanNavigation.test.mjs tests/orphanResultsPosition.test.mjs tests/listExperience.test.mjs tests/navigationAccessibility.test.mjs tests/uiRecordActions.test.mjs`: **24 passed**. Eight cases were added in this batch. They cover search-field routing, leading-zero IDs, applied-query exports, stale-response protection, disclosure focus/listener cleanup, rendered child-section visibility and safe return anchors. Existing preview focus, navigation drawer and guardian-confirmation checks also pass.

`npm run build`: passed; existing unresolved font URL warnings remain. No PHP files changed; PHP formatting and database-backed tests were not run.

Synthetic previews (no real children or live counts): [HTML](orphans-ux-preview.html), [registry desktop](orphans-registry-desktop.png), [child desktop](orphans-child-desktop.png), [registry mobile](orphans-registry-mobile.png). They render the actual page/component templates with test fixtures and omit the global shell. Static preview controls do not execute backend actions. Regenerate after a build with `node tests/Fixtures/renderOrphanWorkspacePreview.mjs` because the preview links hashed CSS assets.

Chrome fixture checks used 1440px and 390px CSS viewports. The registry table fit its container at both sizes; the mobile table used the existing labeled-card layout. There was no document or table-container horizontal overflow in these fixtures. Native disclosure keyboard opening was checked separately; the unit test covers Escape focus restoration. These checks are not a full authenticated or screen-reader accessibility audit.

## Remaining work and approval boundaries

- Full authenticated browser journeys, screen-reader/LTR checks, assignment-aware worklists, negative cross-user endpoint tests and actual export/audit integration tests remain. UI permission tests do not prove backend authorization.
- Approved-registry normalized Arabic name search, exact guardian-ID search, independently paginated queues, full claim/household explorer and secured lazy previews need backend implementation and isolated fixtures.
- The displayed child next action is a conservative guide from its real state, not a new rule engine or assignment/due-date assertion. No unknown owner, deadline, score or financial number is fabricated.
- Sponsorship/campaign approved-rule decision support and accounting/inventory integrations were not changed. No distribution confirmation or guardian approval was added.
- No representative 48k-row dataset, query plans or production timings were measured. No import was run and no source spreadsheet was processed.
- No schema change, live migration, production data alteration, merge or organizational rewrite occurred. Any future searchable index or claim-schema change requires exact impact/rollback/backup planning and explicit approval.

Next backend batch: establish isolated staff/guardian/campaign denial tests, then implement an authorization-scoped search source and separately paginated action queue. Keep relationship claims and legal approval separate throughout.
