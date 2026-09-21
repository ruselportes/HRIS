# HRIS Project Task Tracker

Each phase below is weighted **10%** of total project completion (10 phases = 100%).
Check off tasks as they're completed; a phase counts as done once every task under it is checked.

**Overall progress: ~90% (Phases 1–9 built: Phase 1 14/14, Phase 2 7/7, Phase 3 3/3, Phase 4 5/5, Phase 5 6/6, Phase 6 5/5, Phase 7 8/8, Phase 8 5/5, Phase 9 6/6.)** Two add-ons, the production deployment, and seven web pages that still show "Coming soon" sit outside this count and have their own sections after Phase 10. Verified by 421 backend tests (passing on both SQLite and MySQL 8.0) and 219 mobile tests, `tsc`/ESLint/Pint clean, and both native TurboModules compiling on-device. Claims that still carry asterisks, each spelled out under its phase: hardware-backed keys need a physical handset (the emulator reports `SOFTWARE`); sync runs while the app is alive but not after Android kills it (Phase 6); the < 5 s latency figure needs a real network to measure; and Phase 4's cold-start offline run needs a **release** APK — a debug build fetches its JS bundle from Metro at every launch, so "WiFi off, reopen" always fails regardless of how well the offline code works. The release APK is now built and signed (2026-09-21 — see *Mobile release build* under Operations); what remains on all four asterisks is the run itself on a physical handset — and for the cold-start offline run, first the mobile session-restore fix found 2026-09-21 (see the Phase 4 note).

> **Backend test routine (run before committing backend work):**
> 1. Day-to-day: `docker compose exec api php artisan test` (in-memory SQLite).
> 2. Driver parity: in Docker, `docker compose exec mysql mysql -uroot -proot -e "CREATE DATABASE IF NOT EXISTS hris_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON hris_testing.* TO 'hris'@'%'; FLUSH PRIVILEGES;"` (one-time per container), then `docker compose exec api vendor/bin/phpunit -c phpunit.mysql.xml`. The config in `backend/phpunit.mysql.xml` points tests at `hris_testing` only — the `TestCase` guard refuses any other non-`*_testing` target — so the dev database is never touched. SQLite alone is not enough: it silently rewrites unknown column names (e.g. `id` when the real key is `employee_id`) into string literals, which a MySQL run catches (`Unknown column` errors).

---

## Phase 1 — Foundation & Environment Setup (10%)

- [x] SPMP drafted (`docs/SPMP.md`)
- [x] SRS drafted (`docs/SRS.md`)
- [x] ERD reference note added (`docs/HRIS_ERD_reference.md`)
- [x] SDD finalized and added to `docs/` (`docs/SDD.md` + `docs/SDD.docx`)
- [x] STD drafted and added to `docs/` (`docs/STD.md` + `docs/STD.docx`) — TC-01–TC-06 specified; Actual Result / Pass/Fail left blank until the cases can be executed (TC-01–03 need Phase 5, TC-04–05 need Phase 7, TC-06 needs Phase 8)
- [x] `HRIS_ERD.drawio` added to `docs/`
- [x] Monorepo folder structure created (`backend/`, `web/`, `mobile/`, `docs/`)
- [x] Design prototypes (14 screens) imported to `docs/prototypes/`
- [x] Shared tokens extracted from `HRIS Design System.dc.html` (compiled in `_ds/styles.css` → Tailwind v4 `@theme` in `web/src/index.css`)
- [x] `git init` + initial commit
- [x] Laravel project scaffolded in `backend/` (Laravel 13 + MariaDB `hris` DB)
- [x] React project scaffolded in `web/` (TailwindCSS v4 configured)
- [x] React Native project scaffolded in `mobile/`
- [x] MySQL database created; 13-table schema migrated

## Phase 2 — Employee & Workforce Management (UC-01, UC-02) (10%)

- [x] Migrations: `Role`, `Employee`
- [x] RBAC middleware/policies off `Employee.role_id`
- [x] Authentication (login, session/token handling)
- [x] Employee CRUD API — skills, certifications, pay rate, emergency contact, employment status
- [x] Web: Login & Role Shell screen (from prototype)
- [x] Web: Employee Records screen (from prototype)
- [x] Unit tests: RBAC role enforcement

## Phase 3 — Crew Assignment & Site Deployment (UC-03) (10%)

- [x] Migrations: `Site`, `Crew`, `Crew Assignment` (+ deployment-state migration on `crews.status` / `crews.deployed_at`)
- [x] API: crew formation, site deployment assignment, foreman designation
- [x] Web: Crew Builder screen

## Phase 4 — Offline-Capable Mobile Attendance Checklist (UC-04) (10%)

- [x] React Native navigation/project structure (bottom tabs: Home, Roll call, Timesheet*, Sync* — *ComingSoon stubs for Phase 8/6)
- [x] Local schema: `attendance`, `attendance_sync_queue` + `crew_roster_cache` (SQLite via `@op-engineering/op-sqlite` — swapped in 2026-09-12 after `react-native-sqlite-storage` proved incompatible with this toolchain; SQLCipher swap still deferred to Phase 5, and op-sqlite supports it via a build flag)
- [x] Mobile: Foreman Home screen (roll-call stat cards, online/offline pill, crew roster cache)
- [x] Mobile: Roll Call screen — Present/Late/Absent tap checklist + Undo (matches the actual prototype's 3-way choice, not a time-in/out cycle)
- [x] Offline read/write verified — pure-logic + repository writes go through local SQLite only, no network in the write path

> Note on "offline verified": verified via `npx tsc --noEmit`, ESLint, and 8
> Jest unit tests on the roll-call state machine (`attendanceLogic.ts`), plus
> a confirmed online login on the emulator (2026-09-12). The write path is
> architecturally offline (RollCallScreen only ever calls
> `attendanceRepository`, never `apiClient`), but the cold-start offline case
> is still owed.
>
> **A debug build cannot be used to test or demo offline mode.** Debug APKs do
> not embed the JS bundle — they fetch it from Metro at every launch — so
> "WiFi off, close app, reopen" always fails with "Unable to load script"
> regardless of how well the offline code works. This is a dev-mode artifact,
> not an app defect. The genuine test, and the build to demo at defense, is a
> release APK (`npx react-native run-android --mode=release`), which embeds
> the bundle and runs with no dev server. Budget for this before the defense —
> a panelist asking "now open it with no signal" on a debug build will see a
> red error screen.
>
> **That release build now exists** (2026-09-21, *Mobile release build* under
> Operations): `app-release.apk` embeds the bundle and the native modules, so
> the cold-start offline run — WiFi off, close, reopen — is finally testable
> and still owed, on a handset.
>
> **Found 2026-09-21 — that run will fail on the release APK too.**
> `mobile/src/auth/AuthContext.tsx` restores a session at launch by calling
> `GET /auth/me`, and on **any** error — including no network — it clears the
> saved token. A foreman who reopens the app with no signal is signed out, and
> signing in needs the network, so no roll call can be taken until signal
> returns: offline attendance (FR-05) fails on every offline cold start. Fix:
> clear the token only on a 401/403, keep it on a network error, and restore
> the user from a saved copy (the slim session record — see *Session record*
> in the web-portal section). A second bug in the same function: `auth/me`
> returns `{user: {...}}`, but the restore saves the whole body as `user`, so
> after an online restart `user.first_name`, `user.last_name` and
> `user.employee_id` are undefined — the home and roll-call screens read
> "undefined undefined", and `loadDraft(user.employee_id)` looks under
> `undefined`. Neither is tested: there is no Jest test on the restore path.

## Phase 5 — Cryptographic Attendance Integrity Engine (supports UC-04) (10%)

- [x] Monotonic clock capture — `HrisMonotonicClockModule` TurboModule (`elapsedRealtime`); boot sessions identified via `Settings.Global.BOOT_COUNT`, **not** `currentTimeMillis - elapsedRealtime` (that shifts with the wall clock, so a rollback would read as a new boot session and skip the drift check entirely)
- [x] HMAC-SHA256 hash chaining — `hashChain.ts` ↔ `HashChainVerifier`, cross-verified against PHP with shared test vectors
- [x] TEE ECDSA P-256 signing — `HrisTeeSignerModule` (Android Keystore, StrongBox with TEE fallback). **iOS Secure Enclave not implemented** — Android is primary per the SPMP; iOS would need a parallel Swift module
- [x] `Crypto Signature Ledger` + server-side verification — 3 verifiers, `device_keys` (14th table), `POST /api/attendance/sync` running all three gates
- [x] Mobile: Foreman Device Binding screen — gates the tabs, since capture refuses outright on an unbound device
- [x] Security tests TC-01/02/03 — **automated, against the real endpoint** (`AttendanceSyncTest`): rollback rejected incl. across sync batches, tampered row + everything after it rejected, altered-payload and off-device-key signatures both rejected

> **Two things Phase 5 did NOT deliver, stated plainly:**
>
> 1. **The hardware-backing claim is not yet demonstrated.** An emulator has no
>    TEE and reports `SOFTWARE`; `config('crypto.require_hardware_backed_keys')`
>    defaults `false` so emulator development isn't blocked. The claim only holds
>    on a physical Android device with that flag on. STD §2.1 already specifies a
>    physical device for all mobile test cases — so TC-01–03 pass automatically
>    here, but the *manual* STD runs still need real hardware. The release
    APK (2026-09-21) now makes that run an actual install-and-test, not a
    build exercise.
> 2. **SQLCipher is still deferred.** The HMAC secret sits in AsyncStorage —
>    app-private but unencrypted. Root access reads it and forges self-verifying
>    chain entries. The ECDSA key is non-exportable in the TEE, so that attacker
>    still cannot produce a signature the server accepts: layer 2 degrades, layer
>    3 holds. op-sqlite supports SQLCipher via a build flag; enabling it is the
>    proper hardening.
>
> **Schema additions needing SDD §3.1 + `.drawio` updates (Efren):** `device_keys`
> (new table), `attendances.date` + `attendances.status`, `device_keys` clock-history
> columns. Each is documented in its migration with the reason it was unavoidable.

## Phase 6 — Automated Background Sync Engine (supports UC-04) (10%)

- [x] Background sync service — `syncEngine.ts` (one attempt, no timers) + `SyncScheduler` (single-flight, triggers). Fires on connectivity regained, app foreground, and first mount. **See the scope note below on what "background" covers.**
- [x] Retry queue with exponential backoff — jittered, capped at 5 min; halts (expired sign-in, revoked device, broken chain) never retry
- [x] Server-side sync ingestion — built in Phase 5; this phase **fixed two bugs in it** and added `GET /attendance/sync/status` for lost-response reconciliation
- [x] Mobile: Foreman Sync Queue screen — the prototype's four states, rebuilt from the pre-reflow original in git history
- [x] Sync latency on reconnection — reconnection **pre-empts** any backed-off retry and syncs immediately (tested). An actual < 5 s wall-clock figure needs a device on a real network to measure; the logic cannot be the bottleneck, but the network can

> **Scope of "background":** sync runs whenever the app is alive — foreground,
> or backgrounded but not killed. It does **not** run after Android has killed
> the app. That needs WorkManager (or `react-native-background-fetch`), a new
> native dependency that CLAUDE.md §8 requires flagging, and was not added. The
> SPMP's latency metric is measured *on reconnection*, which the built trigger
> covers; a foreman who closes the app entirely will sync on next open.
>
> **Bugs found and fixed this phase — three, two of them in Phase 5 code:**
> 1. **Clock failures were rejected; TC-01 says flag them.** A rejection stopped
>    the chain tip, so one honest NTP jump orphaned every later event. Now:
>    committed as `verified = false`, audit-logged, and the chain stays continuous
>    — exactly TC-01's expected result, and the prototype's own "Flagged" vs
>    "Failed" distinction.
> 2. **A bad signature let the tip skip the rejected event.** The chain was
>    precomputed independently of the signature gate. Every TC-03 test used a
>    single-event batch, so it never showed.
> 3. **Rebind could never resync.** The device's chain tip was "latest event
>    ever", so after rebinding it linked to pre-rebind history. The chain is now
>    scoped to its binding (`chain_epoch`).
>
> **Chain-break policy — deliberate, not a gap:** after the server rejects an
> event, sync halts for that chain rather than re-signing the orphaned events
> onto the server's tip. Re-signing would launder tampering (the HMAC secret is
> unencrypted locally, so a root attacker could alter the "innocent" successors
> and get a valid TEE signature on the result). Records stay on the phone for HR
> review and Phase 7's retroactive recovery; rebind starts a fresh chain.

## Phase 7 — Foreman Edge Case Handling (UC-05, UC-06, UC-07 — late override, absent foreman, retroactive recovery) (10%)

- [x] Late Foreman Override — default 7:00 AM shift credit + `FOREMAN_LATE_OVERRIDE` audit flag (mobile late-start prompt, "Set each time myself" manual times, server override events with HR approve/reject gating payroll)
- [x] 1-click Absent Foreman crew re-assignment (acting foreman cover: Today / This week, auto-reverts, engineer can end early)
- [x] Retroactive Crew Recovery sign-off workflow (Site Engineer reconstructs, HR signs off or returns; reconstructed records paid only after both)
- [x] `Audit Log` table + service — override events (`OverrideEvents`), acting foreman covers (`ACTING_FOREMAN_ASSIGNED` / `_ENDED`), recovery cases (`RETROACTIVE_RECOVERY`) and each recovery step
- [x] Web: Acting Foreman Reassignment screen (panel on Manpower Allocation's deployment board)
- [x] Web: Attendance Recovery Signoff screen (`/recovery`, Attendance Recovery)
- [x] Web: Late Override Audit screen (`/overrides`, Overrides & Audit)
- [x] Tests: TC-04 late foreman override (`LateOverrideTest`, `RollCallScreen.test.tsx`), TC-05 absent foreman re-assignment (`ActingForemanTest`) — automated; device runs still owed

> **UC-05 — how the override is trusted.** The credit is not a separate message
> from the phone: every credited record carries a signed `override_type`
> (payload v2), and the server both checks it (`TimeInPolicy`: a shift credit
> must be Present, exactly 07:00 site time, and genuinely past the 15-minute
> grace) and builds the audit event from those records — one event per foreman,
> crew and day. Each record keeps its real tap (`captured_at`) beside the
> credited `time_in`, so a rejection repays from the tap.
>
> **Not built from the Late Override Audit prototype**, because nothing records
> them yet: the foreman's reason, device state, gate-log corroboration, leave
> conflicts, per-record approval, "Ask foreman", "Export audit log" and the
> payroll-lock countdown (Phase 8 owns cut-offs).
>
> **UC-06 — acting foreman.** A Site Engineer hands a deployed crew to a free
> Site Foreman in one action; `crews.foreman_id` becomes the acting foreman and
> the regular one is kept in `crews.regular_foreman_id` until `acting_until`
> (end of today or of Sunday, site time). The cover ends on its own — every
> request ends expired covers first, and `crews:end-expired-covers` is
> scheduled — or early from the board. Only a free foreman with a sign-in can
> cover: the phone holds one roster at a time.
>
> Leadership is now **time-aware**. Each period a foreman leads a crew is a
> `crew_assignments` row (`assignment_type` foreman / acting_foreman,
> `started_at`, `ended_at`), ended rather than deleted — TC-05's "history
> preserved through `tbl_crew_assignment.status`". Sync asks who led the crew
> *when the tap was captured*, so a tap taken offline before a handover still
> syncs after it, and one taken after is refused.
>
> On the phone: the binding now records which foreman it belongs to, so a
> second foreman signing in sets the phone up for themselves (warned first if
> the previous foreman has unsent records); Sync gains "Set up this phone
> again"; and a foreman whose crew was handed over has the roster removed.
>
> **Schema added — needs the SDD §3.1 data dictionary and `HRIS_ERD.drawio`
> (Efren):** `crews.regular_foreman_id`, `crews.acting_until`,
> `crew_assignments.assignment_type`, `.started_at`, `.ended_at`, alongside
> Slice 2's `attendances.captured_at`/`override_audit_id` and the
> `audit_logs` review columns.
>
> **Still owed:** TC-04 and TC-05 executed on devices (automated tests cover
> the logic, not the emulator runs).
>
> **UC-07 — retroactive recovery.** A gap is a deployed crew's working day
> (Mon–Sat, `attendance.work_days`) in the last 14 days (`recovery_lookback_days`)
> with no attendance for anyone. The Site Engineer reconstructs it from the
> roster with a cause and a note — the first signature — and HR signs it off or
> returns it with a reason. Reconstructed records carry
> `sync_status = reconstructed`, no device signature, and are paid only once HR
> has signed; roll call that later arrives from the phone replaces them. "No
> work that day" writes no records but still needs HR, since it means nobody is
> paid for the day. Scoped as decided: no gate biometrics, so the prototype's
> "evidence strength" is replaced by a **phone check** — the foreman's phone
> sends records strictly in chain order, so once the server holds a tap from a
> later day, nothing from this day is still waiting on the phone. The case is an
> `audit_logs` entry (as with override events), plus one entry per step.
> Schema: `audit_logs.reason_code` (the cause) — add to the SDD data dictionary.
>
> **Dev environment moved to Docker (2026-09-18).** `compose.yaml` runs the API,
> MySQL 8.0 (the fixed stack's database; XAMPP had MariaDB 10.4), the web
> portal, phpMyAdmin and the Laravel scheduler. Moving to real MySQL surfaced
> one test that only passed on SQLite (JSON key order); the suite now passes on
> both.

## Phase 8 — Philippine Labor Code Payroll Engine (UC-08) (10%)

- [x] `Payroll`, `Payroll Detail` tables — extended: itemised deductions, employer shares, basic/premium pay, readiness, day-by-day breakdown; new `holidays` table; overtime request time windows
- [x] Payroll computation service — regular, OT (1.25x), Night Diff (1.10x), Rest Day (1.30x), Regular Holiday (2.0x), Special Day (1.30x), compounding per the DOLE matrix, unworked regular holidays (Art. 94)
- [x] Deductions / statutory benefits — SSS, PhilHealth, Pag-IBIG, withholding tax (MWE exemption recorded); effective-dated rate sets, all `[VERIFY]`
- [x] Web: Payroll Run screen (`/payroll`) — cut-off picker, compute/recompute, totals, rows with premium hours, payslip with every day and deduction, approval that holds Blocked rows, CSV register export; plus a Holiday calendar tab HR maintains
- [x] Tests: TC-06 holiday payroll computation — revised with the team onto days the system produces (STD.md and STD.docx updated), automated and passing at ₱5,141.25 (`PayrollEngineTest`); run workflow and holiday calendar in `PayrollRunTest`

> **Decided with the team (2026-09-18):** overtime and night differential are
> paid only from approved overtime requests with a start and end time (roll
> call recorded arrival only; see the time-out addendum below for how
> departures now bound the day); a holiday calendar plus the full DOLE matrix;
> itemised, effective-dated statutory deductions; TC-06 revised onto days the
> system can actually produce. Defaults taken, each a config value: cut-offs
> 21st-5th and 6th-20th (per the prototype), shift 07:00-16:00 with an unpaid
> 12:00-13:00 meal hour, Sunday rest day, Late paid from arrival, and monthly
> contributions projected from each cut-off (x2) and deducted half per cut-off.
>
> Payroll holds a worker's row as **Blocked** while any of their attendance is
> not payroll-ready (override awaiting HR, recovery awaiting sign-off, clock
> flag) or their crew has a day awaiting recovery — so nobody is paid short on
> data that may still change. Recovered days show as **Recovered**.
>
> **Run workflow:** a run is every payroll row of one cut-off (`run_code`,
> e.g. `2026-09-A`). HR computes it, and may recompute draft rows at any
> time; approving takes the Ready and Recovered rows (recorded with who and
> when, and in the audit log) and leaves Blocked rows in draft to approve
> once resolved. Approved rows are final. Seeded dev workers now carry daily
> rates, and the 2026 holiday calendar is seeded (`[VERIFY]` against the
> proclamation; the two Eid holidays are added by HR when announced).
>
> Not in Phase 8: 13th month pay (needs a year of basic pay; the `basic_pay`
> column is there for it), SIL conversion, and paid leave counting toward
> holiday eligibility — both wait for Phase 9's leave records.

### Addendum — time-out capture (2026-09-18)

Roll call recorded arrival only, so payroll paid every Present worker to
16:00 whether or not they stayed. Added after Phase 8, in six slices, with
decisions made with the team:

- [x] Signed payload **v3** (`event_type`, `time_out`, `time_out_type`); every event carries the version it was signed under, and v2 and v3 are both verified, so events queued before an update still sync
- [x] Backend: `TimeOutPolicy`; migration `0007_01_01_000000_add_time_out_tracking` (`attendances.time_out_type`, `time_out_captured_at`, `time_out_audit_id`); a stated time-out goes to HR as `MANUAL_TIME_OUT` through its own review link; a flagged time-out is logged but not applied and never touches the signature ledger
- [x] Mobile: **Out** as a worker leaves, **Set out time** (HR-reviewed), **Close shift** from 16:00 (everyone still on site, exactly 16:00, not reviewed), Undo takes back the time-out first; the Sync Queue shows each record's roll call and time-out together
- [x] Payroll: regular hours end at the time-out (undertime, not offset by overtime — Art. 88); approved overtime is paid only until a tapped or stated time-out, never cut by Close shift; a worked day with no time-out is paid to 16:00 with a payslip warning, from `attendance.time_out_tracked_from`
- [x] Web: Overrides & Audit reviews `MANUAL_TIME_OUT` (stated time against when it was entered); the payslip says where a time-out cut a day short
- [x] Docs: `CRYPTOGRAPHY_EXPLAINED.md` (v3), `PH_LABOR_AND_PAYROLL_EXPLAINED.md` §3, QA prep (UI/UX Q2)

> **Still owed:** set `HRIS_TIME_OUT_TRACKED_FROM` to the day the update
> reaches the foremen's phones (default 2026-09-21); an on-device run of Out,
> Close shift and a stated time-out through to sync; and the three new
> `attendances` columns in the SDD §3.1 data dictionary and the ERD (Efren,
> with the Phase 5 and 8 additions).

## Phase 9 — Leave & Overtime Filing + Executive Analytics (UC-10, UC-09) (10%)

- [x] `Leave Request`, `Overtime Request` tables + multi-tier approval workflow — migration 0008 (filer, reason, assigned endorser, endorse/approve/reject columns, overtime `batch_key`); two-hop file → endorse → approve, the endorser assigned at filing (crew leader via `CrewLeadership`, else the site's lowest-id engineer) and reassignable by HR; cancel by the filer while pending, reject with a note, approval final; leave/overtime conflict rules; read scope per role; batch overtime approval with per-row closed-period skips; approved SIL flagged on the payslip; a second HR login seeded (`RequestWorkflowSeeder`). Slices A1–A3.
- [x] Leave/Overtime filing web UI — `/leave`: Leave and Overtime tabs; each row offers only the actions the API allows the viewer (endorse, approve, reject with a note, reassign, cancel); "Needs my action" filter and history per request; new request for self, crew (foreman), site (engineer) or anyone (HR), with overtime for several workers filed as one batch and approvable as one. Overtime filing now requires a window, and the server derives the hours from it.
- [x] Executive Compliance & Analytics backend (UC-09/FR-09) — `GET /api/reports/overview` + `/api/reports/audit` (role:hr,engineer,executive): per-site scorecard (100 − 2×expired certs − overrides − 5×incidents; good ≥85/fair ≥80/watch), company headline as the headcount-weighted average, approved-only labour cost by payslip line date, attendance/leave (rest-day and holiday absences excluded), crew-attributed overrides/incidents, flagged feed, engineer clamped to their own site (refused outright without one); a site is reported only if it is a work site — a crew assigned, or field staff homed there — so office staff activity never makes Head Office a site
- [x] Web: Executive Dashboard screen — `/reports`, from the prototype: period and site filters (engineer fixed to their site), headcount / attendance / overtime cost / company score, labour cost and compliance by site (regular and overtime bars with the score dot), flagged audit feed, full audit log, and a scorecard table showing each site's deductions. Left out rather than faked: the prototype's month comparison, trend sparklines, attendance target marker and manning counts
- [x] Mobile: Foreman Request Form (PR-10) — "File a request" on Foreman Home opens it in place: overtime for the crew (all selected by default, one request each, sharing a batch key) or leave for a crew member or the foreman; Tonight / Tomorrow / Pick date; start and hours steppers with an estimate of the paid overtime; tap-one reasons. The crew comes from the cached roster, so it opens offline; sending needs signal, and a request can be saved as a draft (per foreman, dropped once its date passes, never sent automatically). A worker the server refuses stays in the draft with its reason, and a retry sends only those. The prototype's optional photo is left out: the API has no attachment endpoint
- [x] Documents (slice E) — SRS **§3.2.3 Reports and Analytics Definitions** (window; the per-site score formula and its bands; the headcount-weighted company score; integrity incidents counted as distinct foreman-days; which site each figure is charged to; what makes a work site; the attendance rate's rest-day and holiday exclusions; approved-only leave, overtime and labour cost; the engineer's own-site restriction) and **§3.2.4 Leave and Overtime Workflow Rules**, with FR-09 in §2.2 widened to name the scorecard. STD Table 9.0 traces UC-09 and UC-10 to their automated suites, with a note on why they get no numbered TC and that the acceptance gate stays TC-01–TC-06 — written into both `STD.md` and `STD.docx`. CLAUDE.md §4 records the migration 0008 columns beside the other owed schema additions. **Still owed (Efren):** the SDD §3.1 data dictionary and `HRIS_ERD.drawio` for `device_keys`, `holidays`, the time-out columns and these workflow columns; and the SRS.docx copy of §3.2.3/§3.2.4

## Phase 10 — Integration, Testing & Capstone Defense (10%)

- [ ] Full end-to-end integration testing (web + mobile + API)
- [ ] All STD test cases TC-01–TC-06 passing — every case has automated tests; the manual device runs (TC-01–05) and the payroll walkthrough (TC-06) are still owed on a physical handset — the release APK they need is built (2026-09-21)
- [ ] UAT sign-off from Arcenas Development Corporation stakeholder
- [ ] SPMP / SRS / SDD / STD finalized and packaged
- [ ] Defense presentation and live demo prepared

## Web portal — pages still showing "Coming soon" (found 2026-09-21)

Seven of the 17 nav items in `web/src/config/nav.jsx` were still falling
through to the `:page` catch-all and rendering `ComingSoonPage`. Ten are built:
Dashboard, Employees, Manpower Allocation, Leave Requests, Overrides & Audit,
Attendance Recovery, Payroll Runs, Reports & Analytics, Attendance & DTR and
Device & Sync Health (the last two built 2026-09-21, backend API first, in the
slice below). These are not weighted into the 10 × 10% above — but the Attendance
& DTR item was a documented promise, not an add-on: a panel could fairly have
called the missing read side a gap in the core system. It is no longer missing.

**Built 2026-09-21 (backend gates + tests first, then the screens):**

- [x] **Attendance & DTR** (`attendance`; HR/foreman/engineer full,
      admin/exec view). The one of the SDD's eight web-screen figures with
      nothing built — **Figure 20.0, "web portal attendance monitoring
      interface"** — and SRS §2.2 promises authorized users can "record, view,
      monitor, and manage employee attendance records". A read API for
      attendance now exists: `GET /api/attendance/records`
      (`AttendanceController`, role:hr,foreman,engineer,admin,executive) with
      date range (max 62 days), site, crew and employee filters, RBAC per the
      nav matrix, the engineer limited to their own site (their filter is
      ignored, never widened; no home site is a 403), and the foreman scoped
      to the crews they led at the instant each tap was captured (CrewLeadership
      per record — one-day cover sees exactly that one day). It maps each row's
      credited time-in beside the real tap, the time-out with how it was
      recorded (`time_out_type`: tapped / Close shift / manual), sync and
      recovery state, and pending override/time-out reviews. Times are converted
      from the stored UTC instants to the site timezone before display
      (attendance.timezone, Asia/Manila — a 07:00 tap must read 07:00, not the
      UTC 23:00 it is stored as). One documented limitation: reconstructed
      (recovery) rows have no `captured_at`, so they fall to the "crew's current
      foreman" branch of the DTR scope and are seen by whoever leads the crew
      now, not necessarily whoever led it on that date — accepted because
      foremen never create recovery rows (HR does) and the recovered day still
      shows on HR's and the current foreman's view. Frontend:
      `web/src/pages/AttendancePage.jsx` — period presets and custom window,
      site/crew/employee filters (employee picker hidden for foremen, who
      cannot list the registry), summary cards from a totals summary, and a
      paginated DTR table. Nothing on it writes; approval stays in the
      override/recovery flows.
- [x] **Device & Sync Health** (`synchealth`; admin full, HR view). The only
      revoke was `DELETE /api/me/devices/{deviceId}` under `role:foreman` — a
      foreman revoking their own device, from that device. **A lost or stolen
      phone could not be revoked from the portal**, and it holds a bound
      signing key plus a live auth token in AsyncStorage. Now
      `GET /api/devices` (role:hr,admin) lists `device_keys` — owner (name,
      code, role, site), `security_level`, hardware-backed flag, `bound_at`,
      **`last_synced_at`** (new `device_keys` column, migration 0009, bumped on
      every sync ingest as the online/offline signal; the monotonic clock
      columns only move on trusted events), chain-tip presence (never the
      digest), per-owner integrity incidents (`AuditLog::integrityIncidentsByOwner`,
      distinct foreman-days) — and `DELETE /api/devices/{device}` (role:admin,
      model-bound to the registry key) revokes with a **required** reason so the
      `DEVICE_REVOKED` audit row says why. The foreman's no-reason self-revoke
      is untouched. Frontend: `web/src/pages/SyncHealthPage.jsx` — summary
      cards (registered/online/hardware-backed/incident owners), live "synced
      x ago" times with an offline marker past 4 h, and an admin revoke confirm
      that warns the phone's unsynced records never reach the server and the
      device must be re-bound. A device with no sync yet reads "no sync record",
      never "never synced" — `last_synced_at` only exists from migration 0009
      on, so older bound phones legitimately show it until their next sync
      (review follow-up, 2026-09-21). HR sees the registry read-only ("admin
      only" on the action). The `last_synced_at` column is another SDD §3.1 /
      `drawio` addition owed to Efren.

**Should be built — promised in the documents, or needed to operate:**

- [x] **Holiday calendar** — already built, and in the right place: Payroll
      Runs → *Holiday calendar* tab (`PayrollPage.jsx`, `HolidayCalendar`),
      HR edits and the executive reads, backed by `HolidayController`
      (index/store/destroy, HR the writer). It is deliberately **not** in
      System Settings, which is admin-only while HR owns holidays. (This entry
      said "no screen" until 2026-09-21; that was stale.)

**Every role has placeholders in its sidebar (checked 2026-09-21 against the
`access` matrix in `config/nav.jsx`; the sidebar hides only `none`):**

| Role | "Coming soon" pages in its sidebar |
|---|---|
| HR | Gov't Remittances, Project Sites, Compliance & Docs, Announcements |
| Site Foreman | Roll Call, Compliance & Docs, Announcements |
| Site Engineer | Roll Call, Project Sites, Compliance & Docs, Announcements |
| Admin | Project Sites, Announcements, Users & Roles, System Settings |
| Executive | Gov't Remittances, Project Sites, Compliance & Docs, Announcements |

**Plan to fill them (decided 2026-09-21): six pages built, Announcements
removed.** None of the six needs a schema change — only new `AuditLog`
action-type constants. Each is a slice in the Phase 10 pattern: backend and
tests first, then the screen, then the tick here. Order: C1 → C4 → C2 → C3 →
C5 → C6.

- [ ] **Announcements — removed** (`announce`). Decided 2026-09-21, option
      (a): no use case, and building it needs a new table, a new UC/FR pair
      and more SDD/ERD work for Efren near submission, for the lowest defense
      value of any page. Delete its `nav.jsx` entry with the first slice. If it
      is ever wanted, it is an add-on with team sign-off, as Redis and Add-on
      B were, and its ids would follow UC-11/FR-11 (Add-on B's).
- [ ] **C1 — Compliance & Docs → Certifications** (`compliance`; UC-02
      *Worker Registry and Skill Certification Management*, and UC-09's
      scorecard). **Corrects this file's earlier "no backend, no use case"**:
      certifications are UC-02, already stored in `Employee.certification`
      (name, issuer, number, `issued_at`, `expires_at`), and the SRS §3.2.3
      compliance score already counts the expired ones. A read endpoint lists
      each worker's certifications marked valid / expiring within 30 days /
      expired / no expiry date. `App\Support\CertificationStatus` (already
      shared by the scorecard and the crew reads) is extended to mark each
      certificate, so the page and the scorecard use one rule. Engineer
      clamped to their home site (the `ReportsController` pattern), foreman to
      the crews they lead now (`CrewLeadership`), HR and executive everything.
      Read-only; HR gets a link to the employee record to edit. The nav note
      "Clearances, DOLE, safety certs" becomes "Worker certifications and
      expiry" — no clearance or DOLE documents exist. Test: a site's expired
      count here equals the scorecard's `certifications_expired` for the same
      date.
- [ ] **C4 — Roll Call (web)** (`rollcall`; UC-04, FR-03). A read-only
      "today" monitor: per deployed crew, the records received
      (present/late/absent) against crew size, crews with nothing received
      yet, when the foreman's phone last synced (`last_synced_at`), and
      anything waiting for review. Engineer: own site; foreman: own crews, and
      the foreman's access drops from `full` to `view`. The page says capture
      only happens in the mobile app, since records must be signed on the
      device (FR-10).
- [ ] **C2 — Project Sites** (`sites`; UC-03). The admin creates, renames
      and closes sites; the engineer drops from `full` to `view` (an engineer
      clamped to one site should not create company-wide ones). No delete —
      sites are referenced by employees, crews and report history — so closing
      changes `status`, and closing a site that still has deployed crews is
      refused (422). The page shows location, status, headcount and deployed
      crews; the fixed "11 sites, Cebu & Bohol" note becomes the real count.
      **Cache: nothing new to build** — `AppServiceProvider::INVALIDATES`
      already bumps the `reference`, `employees` and `reports` namespaces on
      any `Site` save or delete; one test asserts the new controller's writes
      retire the cached site list.
- [ ] **C3 — Gov't Remittances** (`gov`; UC-08, FR-08). Monthly totals from
      the month's **approved** A and B runs: SSS, PhilHealth and Pag-IBIG
      employee and employer shares, and BIR withholding tax — all already on
      `payroll_details`. HR sees per-employee rows with SSS, PhilHealth,
      Pag-IBIG and TIN numbers; the executive sees **totals only** (those
      numbers are personal data, and the executive's access is view). Flags
      anyone with contributions but no ID number on file (the company cannot
      remit for them), and marks a month with only one approved run as
      partial. The rates keep their `[VERIFY]` flags. Tests: totals equal the
      payslips, drafts are excluded, and the executive's response carries no
      ID numbers.
- [ ] **C5 — Users & Roles** (`users`; UC-01, FR-01; admin only). The
      sign-in accounts with role, site, employment status, whether a password
      is set, last used and open sessions; "Sign out everywhere" (deletes the
      account's tokens, audit-logged); and role-change history — **no role
      change is audited today**, so the employee update must start recording
      who changed a role, from what, to what (a new `AuditLog` constant, no
      schema change). Add-on B's "Reset portal access" can also sit here.
      **No lockout button** (corrects this entry's earlier wording): the login
      throttle key is `login:` + sha1(identifier|IP) (`AuthController.php:50`),
      per account *and* IP and expiring on its own, so there is no single key
      an admin can clear.
- [ ] **C6 — System Settings** (`settings`; FR-01 — SRS §2.3 has the admin
      manage "system-related configurations"). A read-only page of the values
      in force: attendance timezone, shift times and the late-foreman credit
      time, the payroll periods (fixed semi-monthly A/B, `{code}` =
      `YYYY-MM-A|B`), the statutory rate set in effect, whether hardware-backed
      keys are required and which security levels are accepted, and how long
      sign-in tokens last. Changes are made in config and a redeploy, and the
      page says so — making them editable would need a settings table. It will
      show two honest gaps: hardware keys are not enforced
      (`HRIS_REQUIRE_HARDWARE_KEYS=false`) and sign-in tokens never expire
      (`sanctum.expiration = null`); the second is to be fixed (see *Session
      record* below). The nav note "Cut-offs, holidays, integrations" is
      corrected — holidays live in Payroll Runs.

**Sequencing for these pages:**
- Each needs routes in the signed-in group of `backend/routes/api.php`, which
  Add-on B work also edits: start a slice's backend only when that file has no
  uncommitted Add-on B edits, and stage by explicit path.
- `App.jsx` and `nav.jsx` are edited by Add-on B W3 too: land these pages (and
  the identity and dashboard fixes below) before W3, or pause W3 while they land.
- New routes sit in the signed-in group, so `PortalScopeTest`'s sweep covers
  them automatically; give it a sample value for any new placeholder pattern.
- Page guards allow only `full` or `view`, rather than checking `!== 'none'`.
- Remove each page's fake sidebar badge (`ROLES[...].badges`) as it is built.

**Prototype content still showing on built screens (seen 2026-09-21):** the
shell and the dashboard still render the prototype's fixed per-role content
from `ROLES` in `config/nav.jsx`, not the signed-in user or live data.

- [ ] **Dashboard** (`DashboardPage.jsx`) is the prototype's static stats,
      table and buttons for **every** role — it is counted as built above, and
      it is the first screen a panelist sees:
      - HR: "201 active employees", "184 present", fixed names, cut-off
        "21 Aug – 05 Sep 2026", an "Approve DTR batch" button.
      - Foreman: a fake Roll Call for "Crew B, Site 07" with a "Submit roll
        call" button — on the web, where roll call cannot be taken at all
        (capture is mobile-only).
      - Engineer: "117 on site, 124 required" for "Sites 04, 07, 11" — while
        the real engineer is clamped to one home site everywhere else.
      - Admin: "47 accounts", "3 pending provisioning", "1 locked out", and an
        access-request queue ("no self-registration — every account starts
        here") for a workflow that does not exist.
      - Executive: fixed "August 2026" company figures, beside the real
        Reports & Analytics page whose numbers will not match them.
      Wire each to real figures (the reports, attendance, deployment and device
      endpoints exist) or replace the fake rows with links into the real pages.
- [ ] **Signed-in identity.** The sidebar's "Signed in as" block and the top
      bar's name and initials come from `ROLES[role].user`, one fixed person
      per role, not the account that signed in. Checked against
      `EmployeeSeeder`:
      - **Engineer — wrong on every login, demo included:** it reads
        "Tabotabo, Grace M. · Sites 04, 07, 11", but the only seeded engineer
        is Jomar Abainza.
      - **Foreman — wrong for two of three seeded foremen:** it always reads
        "Dela Cruz, Ronel B. · Site 07 · Structural crew B", so Elmer Bacus
        and Dante Enriquez see Ronel's name.
      - HR (Marilou Reyes), Admin (Francis Uy) and Executive (Ma. Teresa
        Arcenas) match their seeded accounts only by coincidence; any second
        account in those roles shows the wrong name.
      Use the real user from `auth/me` (name, code, role, site).
- [ ] **Session record.** `auth/login` and `auth/me` return the whole
      `EmployeeResource` — daily rate, TIN, SSS, PhilHealth, Pag-IBIG, date of
      birth, address, blood type — and the web keeps it in `localStorage`
      (`hris.user`) after the tab closes. Return a slim session record (id,
      code, names, role, site); pages that need more already read the
      `employees` endpoints. Land it before W3, which puts workers on shared
      phones and computers, and since it settles the `auth/login` shape W3's
      worker sign-in builds on. Everyone signed in must sign in again to clear
      the stored copy. **Token expiry (C6) rides with it for the web only:** a
      foreman's phone can be offline for days, so the mobile app must not lose
      its session to an expiring token — fix the mobile session restore first
      (Phase 4 note), then give web tokens a per-token `expires_at` at login and
      keep the foreman app's token longer than the longest expected offline
      stretch.
- [ ] **Sidebar badges** are fixed per role (`badges` in `ROLES`): Admin
      "Users & Roles 3", "Device & Sync Health 2"; HR "Attendance 12", "Leave
      5", "Overrides 3"; foreman "Roll Call 18", "Leave 2"; engineer "Manpower
      6", "Compliance 4", "Attendance 12"; the executive has none. Most now sit
      on real pages and disagree with them, and two sit on "Coming soon" pages
      (foreman "Roll Call 18", engineer "Compliance 4") — a count of work on a
      page with nothing on it. Drive them from real counts or remove them.
- [ ] **Top bar:** the "Online" pill is always green, the bell does nothing,
      and the search box is a label, not an input. Hide them or wire them.
- [ ] **Coming-soon copy:** `ComingSoonPage` says "The dashboard shows its
      role-specific preview above" — nothing is above it. Reword for any page
      that stays.
- [ ] `DashboardPage.jsx` and `TopBar.jsx` still fall back with
      `ROLES[...] ?? ROLES.hr`, the pattern fixed in `App.jsx` for Add-on B
      W1. Unreachable today (`Shell` rejects an unknown role first); make them
      fail closed anyway.

**Recommended order (2026-09-21):** Attendance & DTR, device revocation and
the holiday calendar are done.

0. The mobile session-restore fix (Phase 4 note) — it blocks the offline
   handset run, a core claim.
1. The session record with web-only token expiry, and the signed-in identity
   on top of it.
2. C1 → C4 → C2 → C3 → C5 → C6, deleting Announcements with C1.
3. The dashboard.
4. Then Add-on B W3.

---

# Add-ons and operations

Work agreed after the ten phases were planned. It is **not** part of the 10 ×
10% weighting above: the phases can be complete while these are in progress,
and a panel question about scope should get that answer plainly.

## Add-on A — Redis cache with clustering

**Asked for:** caching, and clustering so that one Redis node going down does
not push traffic onto MySQL. **Flagged before starting:** Redis is outside the
SPMP's fixed stack (CLAUDE.md §2). **Now recorded** (2026-09-21, R3) in the SPMP,
SRS and SDD, `.md` and `.docx` alike. **Still owed:** the team's sign-off on that
stack amendment.

**One correction worth carrying into the defense.** Falling back to MySQL when
the cache cannot answer is the *safe* behaviour, not the failure. It costs
speed, never correctness. Three separate properties were built:

1. **Surviving one node:** a replica is promoted, and the cache keeps working.
2. **Surviving the whole cache:** every cached read falls back to MySQL instead
   of erroring, which Laravel does **not** do on its own (a Redis exception is
   a 500 unless something catches it). Only the first request pays to find
   the cache down.
3. **Never staler than it has to be:** a write retires the cached reads it
   affects, including a write made while the cache was out.

- [x] **R1 — the cluster** (`97d6ede`): six nodes in `compose.yaml` (three
      primaries, one replica each), formed by a `redis-cluster-init` one-shot
      that re-runs harmlessly. Cache only, with no appendonly and no
      snapshots, so a lost node loses cached copies and nothing else. No host
      ports: a cluster client is redirected to addresses that resolve only
      inside the compose network. phpredis added to `backend/Dockerfile`; a
      named `cluster` connection in `config/database.php` built from
      `REDIS_CLUSTER_NODES`, so a plain single node (or none) still works;
      `phpunit.xml` and `phpunit.mysql.xml` force `CACHE_STORE=array` as both
      `<env>` and `<server>`, so no test run depends on Redis or leaves keys
      in it.
- [x] **R2 — cached reads that fall back** (`afc0f89`): `App\Support\ResilientCache`
      wraps every cached read. Cached: the reports dashboard (2 min), reference
      lists — roles, sites, a year's holidays (10 min), the employee registry
      per filter set (1 min), and the recovery queue's crew-day scan (1 min,
      with its stage/cause filters left live). Invalidation is by version
      counter, bumped on write (`AppServiceProvider::INVALIDATES`), because tag
      flushes and pattern deletes need multi-key operations a cluster spreads
      across slots; each entry's ttl bounds what a missed bump could serve.
- [x] **R2a — the cache cannot break sign-in** (`CACHE_STORE=failover`): the
      login throttle is a cache consumer, and a Redis hiccup was surfacing as
      a 500 on the sign-in page ("Timed out attempting to find data in the
      correct node"), with the raw message shown to the user. The store is now
      Laravel's `failover` driver (Redis first, the database store when Redis
      cannot answer), so every cache user is covered, not only the reads that
      go through `ResilientCache`. The cluster read timeout went from 0.5s to
      2s, which is what tripped on ordinary sign-ins while the cluster was
      healthy. **Correction (2026-09-21):** the verification recorded here
      ("sign-in works with the whole cluster stopped, 1.5–4.5 s; no 500s in 10
      attempts") never touched Redis. The chain was database-then-array the
      whole time (R2d), so those timings are the database store alone. The
      whole-cluster behaviour was first really measured under R2e.
- [x] **R2b — invalidation actually worked in the containers**: the store the
      containers were really using (the database store, as R2d later showed)
      answers `false` to incrementing a key that does not exist, and the
      failover store passes that on. The array store the tests use creates
      the key at 1, and so does Redis. Version counters therefore never
      advanced outside the test suite, so a cached list could have outlived
      the edit that changed it. `bump()` now sets the counter to 2 whenever a
      store answers `false` or 1, with a unit test that does not rely on the
      forgiving store. Verified live then as v1 → v2 → v3, on the database
      store; on Redis, R2g's drill exercises the same path.
- [x] **R2c — only plain data goes in the cache** (`d6b1306`): the *Overrides &
      Audit* site filter crashed (`sites.map is not a function`) because the
      cached reference lists came back as `{}`. Laravel 13 ships
      `serializable_classes => false`, so a leaked `APP_KEY` cannot become a
      gadget chain; as a result, an Eloquent collection read back from the
      cache is an `__PHP_Incomplete_Class`. The first request was right and
      every cached one wrong. Reference lists now cache arrays, and
      `ResilientCache` refuses an object when it is written (a warning in
      production, a failed test under PHPUnit). The regression test runs on
      the database store, because the array store keeps objects untouched,
      which is exactly why this had passed every test.
- [x] **R2d — the cache actually reaches Redis** (`830de58`), found running R3's
      drills: the cluster was empty and the cached entries were in MySQL's
      `cache` table. `config/cache.php` still held the skeleton's own
      `failover` entry (database, then array) below ours, and in PHP a
      repeated array key silently replaces the first. From R2a until this
      fix, the cache never used Redis, and no page and no test showed it: the
      database store gives the same answers, only slower. The duplicate is
      gone, and `CacheConfigTest` pins the chain (it fails on the old file).
- [x] **R2e — a dead cluster costs one slow request, not all of them**
      (`830de58`): Laravel's failover store retries Redis on every call and
      catches the exception itself, so `ResilientCache`'s cooldown never
      engaged. Measured with all six nodes stopped: 5.6–8.4 s on **every**
      request, indefinitely. Now a `CacheFailedOver` from the redis store
      opens the cross-process breaker, the store is rebuilt without Redis for
      the rest of that request, and later requests boot without it until the
      cooldown ends. A read the database answered no longer counts as Redis
      recovering. Measured: ~4.1–4.7 s for the first request, then 0.33–0.94 s;
      sign-in 0.9–1.6 s. `RedisBreakerTest` drives a stand-in `redis` store.
- [x] **R2f — restarts keep the failover** (`830de58`): Docker gave the nodes
      new addresses on every start, while `nodes.conf` remembered the old
      ones. After one recreate, `redis-5` was handed `redis-1`'s old address
      and believed it was its own primary, and two replicas showed as
      `noaddr`, so they could not be promoted, while `cluster_state` read
      `ok`. The six nodes now have fixed addresses on their own network
      (`172.28.200.11`–`.16`). The API seeds from those addresses, because a
      stopped node's *name* took 8 s to fail and its address 0.5 s, and from
      all six, so it can still find a cluster whose first three nodes are
      down. Verified: all six force-recreated at once, topology intact.
- [x] **R2g — an edit made during an outage shows when the cache is back**
      (`42579ab`): while the breaker was open, `bump()` returned early, and
      under the failover store a bump that hit a failing Redis did not even
      throw. Either way Redis's counter never moved, so the copy from before
      the edit could be served until its ttl expired, up to 10 minutes for
      reference lists. A failover window is enough to cause this. Skipped
      namespaces are now owed in the breaker's marker and retired on
      recovery, and the read that finds the cache back re-reads under the new
      version. Verified live: an employee renamed during a failover showed
      the new name on the first read after recovery (`retired: employees,
      reports`).
- [x] **R2h — one failover trips the breaker once** (`c27a045`): the first
      cooldown was 10 s, shorter than a promotion (9.2 s, `cluster_state:ok`
      at ~11 s). A trip early in the window could end before the promotion,
      trip again and fall into the 30 s cooldown. The first cooldown is now
      15 s. Measured: one trip and 18 s of reads computed from MySQL, then
      the copy made before the stop, served from the promoted replica.
- [x] **R3 — documents and runbook** (2026-09-21):
      `docs/REDIS_CLUSTER_RUNBOOK.md` covers what the add-on guarantees and
      what it does not, everyday checks, and four drills (one primary, the
      whole cluster, recovery, an edit during an outage) with the figures
      measured on this stack. It also has troubleshooting and the wording for
      the defense. The stack is recorded in:
      - CLAUDE.md §2;
      - the SPMP (Constraints amendment, resources, tools, infrastructure;
        `.docx`: stack list, definitions, Table 8.0, reference [13]);
      - the SRS §2.1, §2.5 (new *Cache Availability*), §3.1.2 and §3.4.1, in
        both files;
      - the SDD §1.3 (two definitions), §1.4 (reference), the §2 opening and
        a new **§2.2 Caching Layer**, in both files. Word numbers it 2.2.

      The drills found R2d–R2h, and each has a test. **Still owed:** the
      team's sign-off on the stack amendment.
- [ ] **Production parity:** `compose.prod.yaml` has **no Redis**. Production
      therefore uses the database cache (Laravel's default) and none of this
      add-on runs there. Decide: add the cluster to production, run a single
      node there, or state plainly that clustering is demonstrated in
      development only. Until then, the runbook (§1, §9) says plainly that
      production runs without it.

> **Measured on the running stack (2026-09-21), worth quoting rather than
> claiming.** The full table is in the runbook, §10.
>
> - A stopped primary's replica was promoted in 9.2 s (`cluster_state:ok` at
>   about 11 s), with no errors and the pre-stop copy intact. That failover
>   costs about 18 s of reads computed from MySQL.
> - With the whole cluster stopped, one request takes about 4.5 s, then each
>   takes 0.33–0.94 s.
> - Restarted, the cluster re-forms in 4–8 s, empty, and the API returns to
>   Redis on its own.
> - A stopped node rejoins as a replica, because Redis never fails back by
>   itself; `redis-cli cluster failover` on that node restores the layout in
>   under 3 s.
>
> An earlier figure here, "promoted in about 3 s", could not be reproduced: the
> 5 s node timeout alone rules it out. The "1.56 s cold, 0.37 s cached"
> dashboard figures came from R2, when the store was still Redis directly.
> Today this laptop adds 150–650 ms of framework start-up to every request,
> cached or not, so the reliable sign of a cache hit is an unchanged
> `generated_at`, not the timing.
>
> **The failure mode that bit first, and the fix.** With all six nodes stopped,
> the first request took **49 seconds**. The cause was not Redis: Docker's DNS
> takes seconds to fail for a stopped container's name, and the client tries
> every seed. The seeds are now fixed addresses, 0.5 s each to fail. A failure
> opens a breaker that requests share through a marker file, with cooldowns of
> 15 s, 30 s, 1 m, 2 m and 5 m while Redis stays down. One request per cooldown
> pays for the check, and the first success clears it. Every node is still on
> one machine, and one dead machine is still a dead cache. So present this as
> the *mechanism* of high availability, not as production-grade high
> availability.

## Add-on B — Worker self-service portal (view-only)

**Asked for:** let workers into the portal to view their own attendance and
payslip, nothing else. **Decisions taken with the team (2026-09-21):**
self-activation with employee code + date of birth, then the worker sets a
password; web only (no app install for workers); view-only; **the activation
secret is weak on purpose** — the design **detects abuse and recovers from it**
rather than preventing it (to be stated plainly at the defense); a payslip
becomes visible **on HR approval** of the run; a hijacked account is recovered
by HR **setting a temporary password** handed over in person, not by clearing
the password.

**This contradicts a decided position and the documents must change with it:**
Worker and Operator are "record-only, no HRIS login" in CLAUDE.md §5, enforced
by `Role::LOGIN_SLUGS`, and the QA prep has a rehearsed answer to "why can't a
worker see their own attendance?" (§2, Q3). Payslips carry SSS, PhilHealth,
Pag-IBIG, tax and net pay, so the scoping must be exact and tested.

**Two findings that fix the build order (2026-09-21):**

1. **The web app fails open.** `web/src/App.jsx` resolves the shell with
   `ROLES[roleKey(slug)] ?? ROLES.hr`, so any role it does not know —
   including `worker` and `operator` — gets **HR's shell**. Opening sign-in
   before fixing this would drop workers into HR navigation.
2. **Protection is spread across many route groups.** Each API group is guarded
   on its own, by `role:` middleware or a policy. Trusting every one of them to
   deny a new role means auditing every route now and again for every route
   added later. So portal roles are denied by default **at one point on the
   server**, not route by route.

**Order matters — and sign-in stays closed until the portal exists (reviewed
2026-09-21):** there must never be a commit in which a worker can sign in
anywhere without the matching surface (the `/portal` route tree) and without
the lockdown already proven. So W1 **does not open sign-in**: `canSignIn()`
remains staff-only (W1's only change there is refusing *separated* employees),
the route-sweep test proves the lockdown, and sign-in for portal roles opens in
W3 together with the portal and the mobile refusal. An activated worker getting
HTTP 401 on login is W1's proof test.

- [x] **W0 — the use case first (this commit):** UC-11 / FR-11 / PR-11 in
      `SRS.md` (§2.2 + §3.2.1/§3.2.2 rows + §3.2.5, figures 21.0/22.0), the
      SPMP §3.2.1 WBS **group 10.0**, and this section reconciled — before any
      code, per CLAUDE.md §8. The §2.3 roles text and CLAUDE.md §5 changes wait
      for W4.
- [x] **W1 — lock down and the recovery paths (backend, no sign-in change):**
      - `EnsurePortalScope` middleware on the authenticated API group, listed
        `['auth:sanctum', 'portal.scope', 'acting.expire']` and registered
        before `SubstituteBindings` in `app.php`'s priority list, so the 403
        beats model binding (guessing an id yields 403, not 404) and runs before
        the DB-writing `acting.expire`. It allows a `worker`/`operator` only
        `auth/me`, `auth/logout`, `me/attendance*` and `me/payslips*` — matched
        by **route name** (a path prefix has no reliable segment boundary, so
        only exact names can identify an endpoint; route names are therefore
        security-relevant); everything else is 403. The same middleware refuses
        a **separated employee of any role** on every request (only `auth/logout`
        stays open), so a live token cannot outlive the separation.
      - **Route-sweep test:** walk every registered API route carrying
        `auth:sanctum` as a worker and assert **exactly 403** outside the
        allowlist (a 404 counts as a failure), sampling each route from its own
        `wheres` and using a real method, so routes added later are covered
        automatically.
      - `Role::PORTAL_SLUGS = ['worker', 'operator']` (`isPortalRole()`).
        `canSignIn()` in W1 stays **staff-only** and additionally refuses
        separated employees — a behavior change of its own, with its own test
        and commit-message line.
      - `POST /auth/activate` (employee code + date of birth + new password):
        refused once a password is set, for a staff role, or separated; **one
        generic failure for every case** ("Can't activate — contact HR", HTTP
        422, including a rate-limit trip) so it cannot be used to probe codes
        or birthdays; password `min:8`, must not equal the employee code, and
        the password's digit **runs** — checked per contiguous run, not
        concatenated — must not render the date of birth in any common
        ordering (Ymd, dmY, mdY and two-digit-year forms), so separated digit
        groups are not falsely rejected; rate-limited per employee code
        (`activate:code:{sha1(strtolower(trim(code)))}`, 5 per 15 min) **and**
        per IP (`activate:ip:{sha1(ip)}`, 30 per 15 min as an anti-spray
        backstop) — both counters hit on **every** failure, the per-code key
        cleared only on success (the per-IP key decays on its own); activation
        is **atomic** — a `whereNull('password')` update requiring exactly one
        affected row, `Hash::make` applied explicitly — so a worker and an
        attacker racing to the same code have exactly one winner;
        audit-logged `PORTAL_ACTIVATED` with the IP; no token is issued.
        **Review fixes (2026-09-21):** the *route* is registered in W3, not
        here — activation and sign-in open together, and no dead endpoint sits
        on the tunnel. The controller ships in the review-fix commit (strict
        `date_format:Y-m-d` so a `toISOString()` UTC+8 birthday cannot shift a
        day; rate-limiter keys derived only after validation so array input
        cannot 500; digit-run birthday check) and, per the follow-up review,
        the separator-aware run extraction (`/(?<=\d)[-\/. ](?=\d)/`) is in
        and the whole activation suite lives test-only in
        `PortalActivationTest` — see the W3 bullet for what moves there.
      - **HR "Reset portal access"** (`POST employees/{employee}/reset-portal-access`,
        `role:hr,admin`, only ever applied to a portal role): sets a random
        14-character temporary password from an unambiguous charset (no 0/O,
        1/l/I), revokes the worker's tokens, audit-logged `PORTAL_ACCESS_RESET`;
        the password is shown once to HR and handed over in person. Because a
        password is already set, activation stays refused — the hijacker cannot
        simply re-activate. W3 adds the worker's own "Change password"; the
        server never **forces** the rotation (accepted 2026-09-21 — the
        worker-facing surface is the only enforcement), which is the rehearsal
        answer to "can HR log in as a worker?" (a panelist's phrasing; the
        temp password is handed over in person, not kept secret from HR).
      - **Blocker found in the data:** no worker in the dev database has a
        `date_of_birth` (only one foreman does), so activation would refuse
        everyone — HR fills it on the existing employee form, and the demo
        workers get seeded dates (which are public: the prod seed warning gets
        a line about them).
      - `web/src/App.jsx` fail-open fixed now (fail closed), since W3 needs it
        and it is independent of opening sign-in.
- [x] **W2 — their own data only (shipped 2026-09-21):**
      - `GET /me/attendance?from=&to=`: their `attendances` rows — date,
        status, time in and out, how the time-out was recorded, and review
        state in plain words ("Under HR review"). No hashes, signatures, device
        ids or reviewer notes.
      - `GET /me/payslips` and `/me/payslips/{run}`: **approved runs only**
        (`Payroll::APPROVED`, never `draft`), with SSS, PhilHealth, Pag-IBIG
        and tax itemised.
      - No employee id parameter anywhere — everything is scoped to the
        signed-in employee.
      - Tests: worker A cannot read worker B's records; draft runs never
        appear; a guessed `{run}` that belongs to someone else returns 404.
      - Shipped as `PortalController` + `PortalAttendanceRequest`, routes named
        `me.attendance` / `me.payslips` / `me.payslips.show` (the sweep's
        allowlist now names them exactly — wildcards do not match
        segment-wise under `Str::is`). `{run}` is the payroll row id bound by
        a `[0-9]{1,10}` run (a longer id 404s instead of TypeError-500ing),
        never a model, so a draft, a guess, or someone else's run all read as
        the same 404. The sweep seeds one approved run per swept role and hands
        `me/attendance` a valid from/to window. The attendance times shown are
        the ones payroll uses (`effectiveTimeIn/Out`, recorded fallback while
        held) and the review follows `isPayrollReady()` — a rejected override
        shows the real tap with "Not accepted…", anything held reads "On hold
        — ask HR" (review fix, 2026-09-21). Staff roles may call these for
        their own data (deliberate self-service; portal roles stay confined by
        the allowlist). Suite: `PortalDataTest` (10 tests), full backend 433
        passed / 1830 assertions.
- [ ] **W3 — portal sign-in and the web app:**
      - **Move `POST /auth/activate` into `api.php`** — one line in the public
        `auth` group, `->name('auth.activate')`, and it lives with the /portal
        sign-in surface so activation and login open together. Then drop the
        setUp registration in `PortalActivationTest` (it registers the route
        per-test until the real one exists). The whole activation suite is
        already shipped and green in `backend/tests/Feature/PortalActivationTest.php`
        — success, the generic failure matrix (unknown code, wrong DOB,
        `date_format` refusal of an ISO datetime like
        `1990-05-12T16:00:00.000Z` and of impossible dates like `1990-02-30`,
        short password, password == code, a contiguous digit run spelling the
        DOB, a **separator-spelled** DOB like `juan05-12-1990` / `juan1990.05.12`),
        the separated-digit-groups acceptance case (`Moon1-Kite4-Lion0-Star7`),
        per-code 5 and per-IP 30 locks, refuse-already-activated,
        refuse staff/separated, refuse-after-HR-reset. The controller ships
        the fixes (strict `date_format:Y-m-d`, separator-aware digit-**run**
        birthday check, post-validation rate keys) from earlier commits.
      - **Not blocking, deferred to W3 (review 2026-09-21):** the three
        password-only failures — too short, equals the code, spells the
        submitted birthday — could return a specific message instead of
        "contact HR" (a worker who is told what is wrong with the password
        does not lose a try to the generic message), **as long as those checks
        move before the employee lookup** so the specific message cannot
        confirm the code and birthday. The birthday check already runs on the
        submitted date, so a pre-lookup move leaks nothing. These pre-lookup
        failures must bump **only the per-IP counter, not per-code** — they
        are not guesses at the secret, and a worker struggling with the
        password rules must not eat into the code's five attempts (keep the
        per-IP bump as the anti-spray backstop; a specific message can be
        sprayed at nothing, but the DOB/password guesses still want the IP
        throttle).
      - `canSignIn()` accepts the portal roles; login proceeds for them from
        now on.
      - A separate `/portal` route tree: mobile-first layout, no admin
        navigation, only "My attendance" and "My payslips".
      - The fallback for an unknown role stays **denied** (already fixed fail-
        closed in W1; the Shell now also signs the session out there). Portal
        roles on any staff route are sent to `/portal`; staff on `/portal` are
        sent to `/`.
      - An activation screen reached from the login page; a "Reset portal
        access" button on the HR employee record; the worker's own "Change
        password" so the HR temporary password is rotated.
      - **The activation form must send the birthday as strict `Y-m-d`** (not
        `Date.toISOString()`, which shifts a UTC+8 birthday to the previous
        calendar day and would make every worker fail with "contact HR").
      - **The mobile app refuses portal roles** ("Workers use the web
        portal") — otherwise a worker could sign into the foreman app and land
        on a screen of 403s. The issued token is revoked **before** the refusal
        by passing `Authorization: Bearer {token}` explicitly on the logout
        call — the token is not persisted yet, so the interceptor would
        otherwise send the previous user's token and revoke **their** session.
- [ ] **W4 — documents:**
      - SRS §2.3 roles text updated to match §3.2.5 (the supersedes note there
        already points at it); SPMP.md and SRS.md rows/copies already written
        in W0.
      - CLAUDE.md §5: Worker/Operator become portal-login roles.
      - The QA prep answer (§2, Q3) rewritten — its rehearsed answer now says
        the opposite.
      - STD Table 9.0: a row tracing UC-11 to its suites, the same pattern as
        UC-09/10.
      - A note on how credentials are issued in practice, including the
        hijack-then-HR-reset story.
      - **No schema change:** "activated" is a password being set and the
        timestamps live in the audit log, so nothing is added to the SDD
        §3.1 / ERD backlog.

**Commits:** Commit A = W0 docs (this section + SRS §3.2.5 + SPMP WBS group
10.0). Commit B = the whole of W1 in one commit — lockdown, sweep test,
activation, reset, App.jsx fail-closed, seeded DOBs, prod seed warning — with
sign-in still staff-only. Then W2, W3, W4.

**Decided 2026-09-21:** the activation secret's weakness (detect-and-recover,
stated at the defense), payslip visibility on HR approval, and HR-temp-password
resets (the former "clears the password" option is superseded). The committed
SRS §3.2.5 and SPMP group 10.0 encode all three. **Review fixes, same date:**
`auth.activate` registers in W3 (activation + sign-in open together),
`date_format:Y-m-d`, digit-run birthday check, post-validation rate keys,
`assertSuccessful` in the sweep's allowlist branch, the sweep covers
worker *and* operator, the no-role Shell signs the session out, and the
temp-password rotation is not forced server-side (above). **Follow-up review
(second pass):** the birthday check is separator-aware, and the full activation
suite ships now in `PortalActivationTest` (route registered in setUp; W3 moves
one line into api.php and drops that registration). Specific messages for
password-only failures are a not-blocking W3 item (see the W3 bullet).

**On the working tree:** the mobile server-address/release work landed in
`8c65eae`; W1 does not touch mobile at all (the refusal is W3). `.opencode/`
is git-excluded. If `docs/HRIS_Defense_Reviewer_Bisaya.docx` is still modified
when Commit B is made, leave it out of the staging set.

## Operations — production deployment (the old-PC Ubuntu server)

Built alongside the phases; the files are in the repo root and `deploy/`.
`compose.yaml` stays the development stack and is not used on the server.

**What exists**

- [x] `compose.prod.yaml` — MySQL 8.0 (tuned for ~7 GB RAM on a spinning disk:
      1 GB buffer pool, 60 connections, utf8mb4), the API as php-fpm, the
      scheduler (ends expired acting-foreman covers), and nginx. Only nginx
      publishes a port (80); MySQL and php-fpm publish none. Logs are capped
      (10 MB × 3 per service). Data lives in the `mysql-data` and `api-storage`
      volumes. Per-service `mem_limit`s (mysql 2g, api 1g, scheduler 512m, web
      128m, tunnel 64m), the port overridable via `WEB_PORT` (the Windows dev
      box cannot bind 80), `SESSION_DRIVER=file` (the default `database` driver
      has no `sessions` table and the first session-based feature would 500),
      every overridable `HRIS_*` setting mapped through `x-api-env` with its
      default, and `HRIS_MIGRATE_ON_START` env-gated (default `true`) so the
      scheduler does not inherit it.
- [x] `backend/Dockerfile.prod` — multi-stage: Composer install with
      `--no-dev`, an optimised autoloader, and the source baked in (not
      bind-mounted as in development). Production php.ini plus
      `docker/php-prod.ini`: opcache on with `validate_timestamps=0`, since the
      image never changes under a running container. `docker/php-fpm-prod.conf`
      (pool, not php.ini) sets `pm.max_children = 3` — worst case (3 x 256M
      per-worker cap + opcache's shared 128M + the master) fits under the 1g
      cap, so a genuine spike fails with a legible PHP memory error rather
      than an OOM kill.
- [x] `backend/bootstrap/app.php` — trusts the Compose bridge CIDR only
      (`172.16.0.0/12`), limited to the `X-Forwarded-For`/`X-Forwarded-Proto`
      headers nginx mirrors. Spoofed client XFF is ignored, so audit logs and
      login rate-limits key on real client IPs (fix rationale: audit-traceable
      IPs and per-account-per-IP lockout buckets — there is no cross-account DoS
      from the shared container IP). Hardcoded on purpose: `env()` is
      unavailable in the deferred middleware closure under php-fpm's
      `clear_env = yes`. Verified with a spoofed XFF from a non-bridge source
      (recorded the real IP). Caveat: Docker Desktop presents published-port
      clients as the bridge gateway (inside /12), so local tests can look
      spoofed; the Linux server preserves real client IPs via iptables DNAT.
- [x] `backend/docker/entrypoint.prod.sh` — caches config and views at start,
      not at build, because the values come from the environment; runs
      migrations when `HRIS_MIGRATE_ON_START=true`; and runs artisan as
      `www-data`, so php-fpm's workers can write what it creates. No
      `route:cache`: a closure route in `routes/web.php` cannot be serialised.
- [x] `web/Dockerfile.prod` + `web/docker/nginx.conf` — builds the React portal
      and serves it from nginx, which also forwards `/api` and `/up` to php-fpm
      by FastCGI (nginx needs no copy of the backend), sets
      `X-Content-Type-Options`, `X-Frame-Options` and `Referrer-Policy`,
      caches fingerprinted `/assets` for a year, gzips text/json/js/css/svg
      (the JS bundle is ~438 kB), appends the real client to `X-Forwarded-For`
      and mirrors `X-Forwarded-Proto` (Host/Port deliberately not forwarded so
      Laravel cannot be made to trust a client-sent host), falls back to
      `index.html` for BrowserRouter paths, and has a healthcheck on
      `/index.html` — nginx-owned, so the web container's health is not coupled
      to the API's. Gzip covers the tunnel too: `gzip_proxied any` keeps the JS
      bundle compressed even though Cloudflare adds a `Via` header (which
      otherwise makes nginx skip gzip).
- [x] `.env.prod.example` — every secret the stack refuses to start without
      (`DB_PASSWORD`, `DB_ROOT_PASSWORD`, `APP_KEY`, `APP_URL`), with the
      warning that `device_keys.hmac_key` is encrypted with `APP_KEY`, so
      changing it orphans every bound device. `.env.prod` and `backups/` are
      gitignored. The overridable `HRIS_*` settings and `SESSION_DRIVER` are
      also documented here — each is mapped through `compose.prod.yaml`'s
      `x-api-env` (a `.env.prod` value with no mapping is silently ignored), and
      the payroll figures carry their `[VERIFY]` flags.
- [x] `deploy/backup.sh` — nightly `mysqldump` (single transaction, routines)
      gzipped into `backups/`, keeping 14 days, with a cron line in its header
      and a reminder to copy them off the machine.
- [x] Optional `tunnel` profile — a Cloudflare quick tunnel, so the portal is
      reachable outside the LAN with no domain, account or router change.
- [x] First-run note in the compose header: seed **only** `RoleSeeder` and
      `HolidaySeeder`. A bare `db:seed` creates the sample accounts, including
      an admin whose password is literally `password`.

### Mobile release build (2026-09-21)

- [x] `app-release.apk` built, **signed** — 75.6 MB, verified with `apksigner`
      (APK Signature Scheme v2, signer `CN=Arcenas Development Corporation
      HRIS`). All four ABIs (arm64-v8a, armeabi-v7a, x86, x86_64) with op-sqlite
      and the TEESigner glue (`libappmodules.so`), and the embedded JS bundle in
      `assets/index.android.bundle` — no Metro at launch, so offline cold-start
      is genuinely testable. `mobile/android/app/build/outputs/apk/release/app-release.apk`.
- [x] Signing wired into `mobile/android/app/build.gradle`: a `release`
      signingConfig reads `mobile/android/keystore.properties` (storeFile /
      passwords / alias), gitignored via `android/keystore.properties` and the
      existing `*.keystore` rule. On a machine without that file release falls
      back to the debug keystore, exactly as the stock template did.
- [x] Keystore: `mobile/android/app/hris-release.keystore`, RSA 2048, alias
      `hris`, 10,000-day validity. **Back it up** — it and its password are the
      one thing that cannot be re-created. The exact consequence of losing it
      is narrower than it first sounds: Android refuses an update signed with a
      *different* key, so shipping the next fix means uninstall and reinstall —
      and uninstalling wipes the app's Keystore entries **and its local SQLite,
      including any signed-but-unsynced attendance**. So every device re-binds,
      and unsynced records are lost for good. (This has nothing to do with
      `device_keys` — what orphans *those* is rotating `APP_KEY`, per
      CLAUDE.md §4.) The password was generated on the build machine and lives
      in the gitignored `keystore.properties`; move it into a password manager
      too.
- [x] **Server-address field on the login screen.** The out-of-the-box URL is
      the emulator alias (`http://10.0.2.2:8090/api`), which a phone cannot
      reach. The login screen now has a *Server* field: it normalises whatever
      is typed (`normalizeBaseUrl`: trims, defaults to `http://`, forces the
      `/api` suffix), validates it, applies it (`setAndPersistApiBaseUrl`) and
      stores it in AsyncStorage, which `App.tsx` re-applies at every launch
      (`loadApiBaseUrl`). Unit-tested (`src/api/__tests__/client.test.ts`).
      This also covers the quick tunnel: its URL is HTTPS, changes per
      restart, and now needs no rebuild to adopt. So the stable-address decision
      (below) blocks *nothing* — the field accepts any host at runtime.
- [x] **Cleartext for the release build.** React Native's Gradle plugin forces
      `usesCleartextTraffic=false` on release (overriding a `manifestPlaceholders`
      change, so NSC is the way). The manifest now references a
      `network_security_config.xml` (API 24+ makes it authoritative) whose
      `base-config` permits cleartext. **Blanker than the ideal** — decided
      with the team on 2026-09-21: HTTPS on the LAN is still deferred and the
      stable server address is undecided, so whitelisting specific LAN/Tailscale
      IPs would have blocked the handset tests a second time. Once that address
      lands, replace the `base-config` with a `domain-config` whitelisting just
      the server (the tunnel URL is HTTPS and needs no entry). Verified in the
      built APK: manifest carries `networkSecurityConfig` (no
      `usesCleartextTraffic`), compiled base-config `cleartextTrafficPermitted=true`.
- [ ] Physical-handset tasks now unblocked (each still owed): Phase 4's
      cold-start offline run, TC-01–03 with `HRIS_REQUIRE_HARDWARE_KEYS=true`,
      TC-04/05, and the demo install. On the hardware-backing claim, note the
      acceptance rule (`config/crypto.php:61`): **STRONGBOX *or*
      TRUSTED_ENVIRONMENT** passes — only `SOFTWARE` fails, so a TEE-only
      mid-range phone passing the flag is a pass, not a miss; StrongBox itself
      needs a discrete secure element (Pixel 3+ and some flagships).

**Owed before anyone outside the team uses it**

- [ ] **HTTPS on the LAN.** Decided with the team (review, production): the
      origin stays plain HTTP for the demo. The internet hop is already TLS
      inside the quick tunnel; the genuinely plaintext segment is the LAN
      itself, where the mobile app's bearer token crosses the wire in the clear
      — documented in the compose header. Still owed before real use outside
      the demo: put HTTPS in front (self-signed or a named tunnel).
- [ ] **A stable address for the mobile app.** A quick tunnel gets a new random
      URL each restart, and the app's base URL is a dev default
      (`10.0.2.2:8090`, overridable through `setApiBaseUrl`). Decide: a named
      tunnel with a domain, a Tailscale address, or a LAN IP, and wire it into
      the release build.
- [ ] **Restore drill.** Backups are written but never yet restored. Restore
      into a scratch database and confirm the payroll figures and the crypto
      ledger survive it.
- [ ] **Cache parity** with Add-on A (see above).
- [ ] **A queue worker, if anything starts queueing.** `QUEUE_CONNECTION`
      defaults to `database` and no worker container runs, so a queued job
      would sit unprocessed. Nothing queues today.
- [ ] **Deployment checklist for the defense:** the exact commands, who runs
      them, and what "healthy" looks like (`hris ps`, `/up`, a sign-in).
