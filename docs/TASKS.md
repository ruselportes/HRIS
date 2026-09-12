# HRIS Project Task Tracker

Each phase below is weighted **10%** of total project completion (10 phases = 100%).
Check off tasks as they're completed; a phase counts as done once every task under it is checked.

**Overall progress: ~40% (Phases 1–4 complete: Phase 1 14/14, Phase 2 7/7, Phase 3 3/3, Phase 4 5/5.) The mobile app now builds and installs on an emulator as of 2026-09-12 — the Android native build was blocked until then, so Phase 4's offline behaviour has still only been proven by tsc/ESLint/Jest + 50 backend tests, never by an actual airplane-mode run on the device. That run is now possible and is the first thing owed.**

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

- [ ] Monotonic clock capture (`elapsedRealtime` / `mach_continuous_time`)
- [ ] HMAC-SHA256 hash chaining ledger
- [ ] TEE / Secure Enclave ECDSA P-256 signing
- [ ] `Crypto Signature Ledger` table + server-side verification service
- [ ] Mobile: Foreman Device Binding screen
- [ ] Security tests: TC-01 clock rollback, TC-02 DB tampering, TC-03 MitM signature forgery

## Phase 6 — Automated Background Sync Engine (supports UC-04) (10%)

- [ ] Background sync service (mobile)
- [ ] Retry queue with exponential backoff
- [ ] Server-side sync ingestion endpoint
- [ ] Mobile: Foreman Sync Queue screen
- [ ] Sync latency validated (< 5 seconds on reconnection)

## Phase 7 — Foreman Edge Case Handling (UC-05, UC-06, UC-07 — late override, absent foreman, retroactive recovery) (10%)

- [ ] Late Foreman Override — default 7:00 AM shift credit + `FOREMAN_LATE_OVERRIDE` audit flag
- [ ] 1-click Absent Foreman crew re-assignment
- [ ] Retroactive Crew Recovery sign-off workflow
- [ ] `Audit Log` table + service
- [ ] Web: Acting Foreman Reassignment screen
- [ ] Web: Attendance Recovery Signoff screen
- [ ] Web: Late Override Audit screen
- [ ] Tests: TC-04 late foreman override, TC-05 absent foreman re-assignment

## Phase 8 — Philippine Labor Code Payroll Engine (UC-08) (10%)

- [ ] `Payroll`, `Payroll Detail` tables
- [ ] Payroll computation service — regular, OT (1.25x), Night Diff (1.10x), Rest Day (1.30x), Holiday (2.0x)
- [ ] Deductions / statutory benefits
- [ ] Web: Payroll Run screen
- [ ] Tests: TC-06 holiday payroll computation, verified against manual calculations

## Phase 9 — Leave & Overtime Filing + Executive Analytics (UC-10, UC-09) (10%)

- [ ] `Leave Request`, `Overtime Request` tables + multi-tier approval workflow
- [ ] Leave/Overtime filing web UI
- [ ] Executive Compliance & Analytics Dashboard (labor cost, site punctuality, audit review)
- [ ] Web: Executive Dashboard screen

## Phase 10 — Integration, Testing & Capstone Defense (10%)

- [ ] Full end-to-end integration testing (web + mobile + API)
- [ ] All STD test cases TC-01–TC-06 passing
- [ ] UAT sign-off from Arcenas Development Corporation stakeholder
- [ ] SPMP / SRS / SDD / STD finalized and packaged
- [ ] Defense presentation and live demo prepared
