# HRIS Project Task Tracker

Each phase below is weighted **10%** of total project completion (10 phases = 100%).
Check off tasks as they're completed; a phase counts as done once every task under it is checked.

**Overall progress: ~90% (Phases 1–9 built: Phase 1 14/14, Phase 2 7/7, Phase 3 3/3, Phase 4 5/5, Phase 5 6/6, Phase 6 5/5, Phase 7 8/8, Phase 8 5/5, Phase 9 6/6.)** Two add-ons and the production deployment sit outside this count and have their own section after Phase 10. Verified by 363 backend tests (passing on both SQLite and MySQL 8.0) and 219 mobile tests, `tsc`/ESLint/Pint clean, and both native TurboModules compiling on-device. Claims that still carry asterisks, each spelled out under its phase: hardware-backed keys need a physical handset (the emulator reports `SOFTWARE`); sync runs while the app is alive but not after Android kills it (Phase 6); the < 5 s latency figure needs a real network to measure; and Phase 4's cold-start offline run needs a **release** APK — a debug build fetches its JS bundle from Metro at every launch, so "WiFi off, reopen" always fails regardless of how well the offline code works.

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
>    here, but the *manual* STD runs still need real hardware.
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
- [ ] All STD test cases TC-01–TC-06 passing — every case has automated tests; the manual device runs (TC-01–05) and the payroll walkthrough (TC-06) are still owed, on a physical handset and a release APK
- [ ] UAT sign-off from Arcenas Development Corporation stakeholder
- [ ] SPMP / SRS / SDD / STD finalized and packaged
- [ ] Defense presentation and live demo prepared

---

# Add-ons and operations

Work agreed after the ten phases were planned. It is **not** part of the 10 ×
10% weighting above: the phases can be complete while these are in progress,
and a panel question about scope should get that answer plainly.

## Add-on A — Redis cache with clustering

**Asked for:** caching, and clustering so that one Redis node going down does
not push traffic onto MySQL. **Flagged before starting, still owed:** Redis is
outside the SPMP's fixed stack (CLAUDE.md §2), so the SPMP, SRS §2 and the SDD
have to record it and the team has to sign it off.

**One correction worth carrying into the defense.** Falling back to MySQL when
the cache cannot answer is the *safe* behaviour, not the failure — it costs
speed, never correctness. Two separate properties were built:

1. **Surviving one node:** a replica is promoted, and the cache keeps working.
2. **Surviving the whole cache:** every cached read falls back to MySQL instead
   of erroring, which Laravel does **not** do on its own — a Redis exception is
   a 500 unless something catches it.

- [x] **R1 — the cluster** (`97d6ede`): six nodes in `compose.yaml` (three
      primaries, one replica each), formed by a `redis-cluster-init` one-shot
      that re-runs harmlessly. Cache only — no appendonly, no snapshots — so a
      lost node loses cached copies and nothing else. No host ports: a cluster
      client is redirected to addresses that resolve only inside the compose
      network. phpredis added to `backend/Dockerfile`; a named `cluster`
      connection in `config/database.php` built from `REDIS_CLUSTER_NODES`, so
      a plain single node (or none) still works; `phpunit.xml` and
      `phpunit.mysql.xml` force `CACHE_STORE=array` as both `<env>` and
      `<server>`, so no test run depends on Redis or leaves keys in it.
- [x] **R2 — cached reads that fall back** (`afc0f89`): `App\Support\ResilientCache`
      wraps every cached read. Cached: the reports dashboard (2 min), reference
      lists — roles, sites, a year's holidays (10 min), the employee registry
      per filter set (1 min), and the recovery queue's crew-day scan (1 min,
      with its stage/cause filters left live). Invalidation is by version
      counter, bumped on write (`AppServiceProvider::INVALIDATES`), because tag
      flushes and pattern deletes need multi-key operations a cluster spreads
      across slots; each entry's ttl bounds what a missed bump could serve.
- [ ] **R3 — documents and runbook:** the stack change in CLAUDE.md §2, the
      SPMP, SRS §2 and SDD; a failover runbook (kill a primary, watch the
      replica take over, kill the cluster, watch the fallback); and the
      defense wording for what this does and does not prove.
- [ ] **Production parity:** `compose.prod.yaml` has **no Redis**. Production
      therefore uses the database cache (Laravel's default) and none of this
      add-on runs there. Decide: add the cluster to production, run a single
      node there, or state plainly that clustering is demonstrated in
      development only.

> **Measured on the running stack, worth quoting rather than claiming:**
> dashboard 1.56 s cold and 0.37 s cached; stopping a primary promoted its
> replica in about 3 s with the cached value intact and the API reading and
> writing throughout, no restart; the stopped node rejoined as a replica.
>
> **The failure mode that actually bit, and the fix.** With all six nodes
> stopped the first request took **49 seconds** — far worse for a user than an
> error. The cause was not Redis: Docker's DNS takes about 4 s to fail for a
> stopped container's name, and the client tries every seed. Now the seed list
> is three nodes, the cluster connection has a 0.5 s timeout, and a failure
> puts the cache out of use for a growing cooldown (10 s, 30 s, 1 m, 2 m, 5 m)
> shared between requests through a marker file, since each request is its own
> PHP process. One request per cooldown pays the discovery; the rest answer in
> about 0.2 s from MySQL, logged once per cooldown. The first success clears
> it. On a real server DNS for a dead host fails faster, so this is mostly a
> containers-on-one-laptop effect — say so rather than presenting the cluster
> as production-grade high availability: every node is on one machine, and one
> dead machine is still a dead cache.

## Add-on B — Worker self-service portal (view-only)

**Asked for:** let workers into the portal to view their own attendance and
payslip, nothing else. **Decisions taken with the team:** self-activation with
employee code + date of birth, then the worker sets a password; web only (no
app install for workers); view-only.

**This contradicts a decided position and the documents must change with it:**
Worker and Operator are "record-only, no HRIS login" in CLAUDE.md §5, enforced
by `Role::LOGIN_SLUGS`, and the QA prep has a rehearsed answer to "why can't a
worker see their own attendance?" (§2, Q3). Payslips carry SSS, PhilHealth,
Pag-IBIG, tax and net pay, so the scoping must be exact and tested.

- [ ] **W1 — activation and login:** open `worker`/`operator` to sign-in;
      `POST /auth/activate` (employee code + date of birth → set password),
      rate-limited and locked like login, refused once a password exists, and
      written to the audit log. **Blocker found in the data:** no worker in the
      dev database has a `date_of_birth` (only one foreman does), so activation
      would refuse everyone — HR fills it on the existing employee form, and
      the demo workers get seeded dates.
- [ ] **W2 — their own data only:** `GET /me/attendance` (their roll call, with
      time-out and any review state in plain words) and `GET /me/payslips` +
      `/me/payslips/{run}` (approved payroll runs only). Scoped to the signed-in
      employee, with tests that another worker's records cannot be reached by
      guessing an id.
- [ ] **W3 — the portal itself:** a view-only area in the web app (usable in a
      phone browser), no admin navigation, and a guard that a worker cannot
      reach any other route.
- [ ] **W4 — documents:** SRS §2.3 roles and a new UC-11/FR-11, the QA prep
      answer rewritten, CLAUDE.md §5, and a note on how credentials are issued
      in practice.

## Operations — production deployment (the old-PC Ubuntu server)

Built alongside the phases; the files are in the repo root and `deploy/`.
`compose.yaml` stays the development stack and is not used on the server.

**What exists**

- [x] `compose.prod.yaml` — MySQL 8.0 (tuned for ~7 GB RAM on a spinning disk:
      1 GB buffer pool, 60 connections, utf8mb4), the API as php-fpm, the
      scheduler (ends expired acting-foreman covers), and nginx. Only nginx
      publishes a port (80); MySQL and php-fpm publish none. Logs are capped
      (10 MB × 3 per service). Data lives in the `mysql-data` and `api-storage`
      volumes.
- [x] `backend/Dockerfile.prod` — multi-stage: Composer install with
      `--no-dev`, an optimised autoloader, and the source baked in (not
      bind-mounted as in development). Production php.ini plus
      `docker/php-prod.ini`: opcache on with `validate_timestamps=0`, since the
      image never changes under a running container.
- [x] `backend/docker/entrypoint.prod.sh` — caches config and views at start,
      not at build, because the values come from the environment; runs
      migrations when `HRIS_MIGRATE_ON_START=true`; and runs artisan as
      `www-data`, so php-fpm's workers can write what it creates. No
      `route:cache`: a closure route in `routes/web.php` cannot be serialised.
- [x] `web/Dockerfile.prod` + `web/docker/nginx.conf` — builds the React portal
      and serves it from nginx, which also forwards `/api` and `/up` to php-fpm
      by FastCGI (nginx needs no copy of the backend), sets
      `X-Content-Type-Options`, `X-Frame-Options` and `Referrer-Policy`,
      caches fingerprinted `/assets` for a year, and falls back to `index.html`
      for BrowserRouter paths.
- [x] `.env.prod.example` — every secret the stack refuses to start without
      (`DB_PASSWORD`, `DB_ROOT_PASSWORD`, `APP_KEY`, `APP_URL`), with the
      warning that `device_keys.hmac_key` is encrypted with `APP_KEY`, so
      changing it orphans every bound device. `.env.prod` and `backups/` are
      gitignored.
- [x] `deploy/backup.sh` — nightly `mysqldump` (single transaction, routines)
      gzipped into `backups/`, keeping 14 days, with a cron line in its header
      and a reminder to copy them off the machine.
- [x] Optional `tunnel` profile — a Cloudflare quick tunnel, so the portal is
      reachable outside the LAN with no domain, account or router change.
- [x] First-run note in the compose header: seed **only** `RoleSeeder` and
      `HolidaySeeder`. A bare `db:seed` creates the sample accounts, including
      an admin whose password is literally `password`.

**Owed before anyone outside the team uses it**

- [ ] **HTTPS.** The portal is plain HTTP on port 80. Passwords, payslips and
      Sanctum tokens cross the network in the clear on the LAN. A quick tunnel
      gives HTTPS only for the tunnelled hostname.
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
