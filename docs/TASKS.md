# HRIS Project Task Tracker

Each phase below is weighted **10%** of total project completion (10 phases = 100%).
Check off tasks as they're completed; a phase counts as done once every task under it is checked.

**Overall progress: ~9% (Phase 1: 12/14 items — only SDD & STD deliverables pending, all scaffolding functionally verified)**

---

## Phase 1 — Foundation & Environment Setup (10%)

- [x] SPMP drafted (`docs/SPMP.md`)
- [x] SRS drafted (`docs/SRS.md`)
- [x] ERD reference note added (`docs/HRIS_ERD_reference.md`)
- [ ] SDD finalized and added to `docs/`
- [ ] STD finalized and added to `docs/`
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

- [ ] Migrations: `Role`, `Employee`
- [ ] RBAC middleware/policies off `Employee.role_id`
- [ ] Authentication (login, session/token handling)
- [ ] Employee CRUD API — skills, certifications, pay rate, emergency contact, employment status
- [ ] Web: Login & Role Shell screen (from prototype)
- [ ] Web: Employee Records screen (from prototype)
- [ ] Unit tests: RBAC role enforcement

## Phase 3 — Crew Assignment & Site Deployment (UC-03) (10%)

- [ ] Migrations: `Site`, `Crew`, `Crew Assignment`
- [ ] API: crew formation, site deployment assignment, foreman designation
- [ ] Web: Crew Builder screen

## Phase 4 — Offline-Capable Mobile Attendance Checklist (UC-04) (10%)

- [ ] React Native navigation/project structure
- [ ] Local schema: `Attendance`, `Attendance Sync Queue` (SQLite + SQLCipher, AES-256)
- [ ] Mobile: Foreman Home screen
- [ ] Mobile: Foreman Attendance Mobile (tap-based checklist) screen
- [ ] Offline read/write verified with no connectivity

## Phase 5 — Cryptographic Attendance Integrity Engine (UC-06, UC-07, UC-08) (10%)

- [ ] Monotonic clock capture (`elapsedRealtime` / `mach_continuous_time`)
- [ ] HMAC-SHA256 hash chaining ledger
- [ ] TEE / Secure Enclave ECDSA P-256 signing
- [ ] `Crypto Signature Ledger` table + server-side verification service
- [ ] Mobile: Foreman Device Binding screen
- [ ] Security tests: TC-01 clock rollback, TC-02 DB tampering, TC-03 MitM signature forgery

## Phase 6 — Automated Background Sync Engine (UC-09) (10%)

- [ ] Background sync service (mobile)
- [ ] Retry queue with exponential backoff
- [ ] Server-side sync ingestion endpoint
- [ ] Mobile: Foreman Sync Queue screen
- [ ] Sync latency validated (< 5 seconds on reconnection)

## Phase 7 — Foreman Edge Case Handling (UC-05/10, late override, absent foreman, retroactive recovery) (10%)

- [ ] Late Foreman Override — default 7:00 AM shift credit + `FOREMAN_LATE_OVERRIDE` audit flag
- [ ] 1-click Absent Foreman crew re-assignment
- [ ] Retroactive Crew Recovery sign-off workflow
- [ ] `Audit Log` table + service
- [ ] Web: Acting Foreman Reassignment screen
- [ ] Web: Attendance Recovery Signoff screen
- [ ] Web: Late Override Audit screen
- [ ] Tests: TC-04 late foreman override, TC-05 absent foreman re-assignment

## Phase 8 — Philippine Labor Code Payroll Engine (UC-13) (10%)

- [ ] `Payroll`, `Payroll Detail` tables
- [ ] Payroll computation service — regular, OT (1.25x), Night Diff (1.10x), Rest Day (1.30x), Holiday (2.0x)
- [ ] Deductions / statutory benefits
- [ ] Web: Payroll Run screen
- [ ] Tests: TC-06 holiday payroll computation, verified against manual calculations

## Phase 9 — Leave & Overtime Filing + Executive Analytics (UC-14, UC-15) (10%)

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
