# HRIS — Human Resource Information System
### for Arcenas Development Corporation (Capstone Project)

This file is auto-loaded by Claude Code at the start of every session in this repo.
Keep it updated as decisions get finalized — treat it as the single source of truth
for project context, so you don't have to re-explain the project every time.

## 1. What this system does

HRIS digitizes and centralizes HR + payroll operations for a construction company,
with special focus on:
- Recording daily attendance for construction crews across multiple sites,
  **including remote sites with no internet connectivity**.
- Preventing timestamp manipulation/tampering on field devices via cryptographic
  hardware-backed signing.
- Automating payroll computation in compliance with the **Philippine Labor Code**.

## 2. Fixed technology stack (do not change without team sign-off)

| Layer | Technology |
|---|---|
| Backend / API | Laravel 8+ (PHP 8.x) |
| Web frontend | React.js + TailwindCSS |
| Mobile app | React Native (Android primary, iOS secondary) |
| Central DB | MySQL 8.0 |
| Mobile local DB | SQLite + SQLCipher (AES-256) |
| Crypto | HMAC-SHA256 hash chaining, ECDSA P-256 signing |
| Hardware security | Android Keystore TEE / iOS Secure Enclave |
| Monotonic time source | `elapsedRealtime` (Android) / `mach_continuous_time` (iOS) |
| Version control | Git (GitHub/GitLab) |

## 3. Core modules

1. Employee & Workforce Management
2. Crew Assignment & Site Deployment
3. Offline-Capable Mobile Attendance Checklist (React Native, tap-based)
4. Cryptographic Attendance Integrity Engine (4-layer: monotonic clock →
   HMAC-SHA256 chain → ECDSA P-256 TEE/Secure Enclave signing → server-side
   verification)
5. Automated Background Sync Engine (offline → online reconnection)
6. Foreman Edge Case Handling:
   - Late foreman override → default 7:00 AM shift credit + `FOREMAN_LATE_OVERRIDE` audit flag
   - Absent foreman → 1-click crew re-assignment
   - Retroactive crew recovery sign-off workflow
7. Philippine Labor Code Payroll Engine — OT 1.25x, Night Differential 1.10x,
   Rest Day 1.30x, Holiday 2.0x (RA 442, Articles 83, 86, 87, 93, 94)
8. Leave & Overtime Filing Workflow (multi-tier approval)
9. Executive Compliance & Analytics Dashboard

## 4. Database (13 ERD tables + 1 documented Phase 5 addition)

Per SDD Section 3.1 data dictionary (see `/docs/HRIS_ERD.drawio` and
`/docs/HRIS_ERD_reference.md` for the full ERD):

`Role`, `Employee`, `Site`, `Crew`, `Crew Assignment`, `Attendance`,
`Attendance Sync Queue`, `Crypto Signature Ledger`, `Payroll`, `Payroll Detail`,
`Leave Request`, `Overtime Request`, `Audit Log`.

- RBAC runs off `Employee.role_id → Role` — **no separate users/login table.**
- `Employee.certification` and `Employee.emergency_contact` fields exist per the
  data dictionary (may be missing from older SDD figures — data dictionary wins).

**`device_keys` — 14th table, added Phase 5** (`0004_01_01_000000_create_device_keys_table`).
Not in the ERD, deliberately and documentedly added because the ERD models no
device registry at all: STD TC-03 requires a device's TEE public key to be
"registered on the server", and TC-02 requires the server to recompute each
record's HMAC, so the per-device HMAC secret must be server-known too. There is
no existing column anywhere that could hold either — `Attendance Sync Queue`
carries only a bare `device_id` string. This is unlike the `certifications`
table proposed in Phase 3, which was correctly **rejected** as redundant since
`Employee.certification` already existed.

Holds: `employee_id` FK, unique `device_id`, `public_key` (PEM, P-256),
`hmac_key` (encrypted at rest via APP_KEY — rotating APP_KEY orphans every
device), `security_level` (Android KeyInfo: STRONGBOX / TRUSTED_ENVIRONMENT /
SOFTWARE), `last_chain_hash` (enforces chain continuity *across* sync batches,
not just within one), `bound_at`, `revoked_at`.

> ⚠️ **Still owed:** this table needs adding to the SDD §3.1 data dictionary and
> `HRIS_ERD.drawio` before submission — Efren's ownership. The table count in
> those documents will otherwise contradict the migrations.

## 5. User roles

As finalized in Phase 2 (`backend/database/seeders/RoleSeeder.php`), `Role` has
7 rows; only 5 are login-capable (`Employee.password` set), Worker/Operator are
record-only classifications with no HRIS access:

- **HR Personnel** — employee records, attendance, leave, payroll
- **Site Foreman** — mobile attendance logging (primary offline users)
- **Site Engineer / Construction Manager** — crew assignment, site monitoring
- **System Administrator** — accounts, permissions, config
- **Executive** — analytics dashboards, audit review (read-only)
- **Worker** — field worker on a crew; record-only, no HRIS login
- **Operator** — heavy equipment operator; record-only, no HRIS login

## 6. Where the docs live

**Team and advisers** (as carried on the title page of all four documents):
Jay Mark A. Reños (Project Manager), Liza Mae C. Sugala (Systems Analyst),
Rusel R. Portes (Lead Developer — also Team Leader; Team Leader and Project
Manager are *different roles*, do not substitute one for the other),
Efren S. Cabudbud Jr. (Database & QA Lead), CarlVey Sente (UI/UX Designer),
Rayla G. Lanaza (Documentation Lead) — 6 members, spelled **CarlVey** (one
word). Advisers: **Eric Bulala = Technical Adviser** (the adviser to name when
a form or document asks for one); Engr. Clark Kevin V. Villamor = Subject
Adviser / Head, College of Computer Studies, and Project Title Reviewer.
Both are named per `C:\capstone\internal\Request-Letter-to-Conduct-a-Study.docx`.

- `/docs/SPMP.md` — Software Project Management Plan. Its WBS (§3.2.1) tags
  work items with `UC-xx`/`FR-xx`/`PR-xx` IDs that are cross-referenced to
  SRS §3.2.1/§3.2.2 — keep these two in sync if either changes.
  **This markdown and `/docs/SPMP.docx` are different plans, not two copies
  of one** — see the SPMP.docx entry below before syncing either direction.
- `/docs/SPMP.docx` — the Word SPMP, for submission (source copy lives in the
  user's Downloads). Corrected 2026-09-11: stale `UC-01 to UC-15` → `UC-01 to
  UC-10`, and the UI/UX row of Table 3.0 had literal `<br>` markup and a stray
  markdown `|` showing as body text — rebuilt as real line breaks with
  `PR-01 to PR-10` added. It still diverges from `SPMP.md` in substance: it
  schedules by calendar date (Jul 2026 - Mar 2027, 3 iterations) where
  SPMP.md uses abstract Week 1-18/8 phases, and its WBS is a labor-hour
  table rather than SPMP.md's 9 UC-tagged groups. Do not overwrite either
  from the other without asking — that divergence is unresolved. (The team
  mismatch is resolved: **CarlVey Sente (UI/UX Designer)** was missing from
  SPMP.md and was added there on 2026-09-11, splitting the former combined
  "UI/UX & Documentation Lead" into UI/UX Designer (Sente) and Documentation
  Lead (Lanaza), matching the docx. The team is 6, not 5.) A title-page block
  (version, date, prepared-by, both advisers) was added 2026-09-12 — it had
  none at all before.
- `/docs/SRS.md` — Software Requirements Specification. §3.2.1/§3.2.2 define
  the canonical `UC-01`–`UC-10` / `FR-01`–`FR-10` / `PR-01`–`PR-10` IDs (10
  each, not 15 — an earlier SPMP draft assumed 15 before the IDs existed).
  UC-10 (Leave & Overtime Filing and Approval) was added late since the
  module already existed elsewhere (SDD §2.1.7/§3.1.11-12/§4.6, SPMP WBS
  4.6) but had no SRS use case of its own until then. §2.3 lists all 7
  roles per `RoleSeeder.php` (Executive/Worker/Operator were missing until
  reconciled against CLAUDE.md's §5 role list).
- `/docs/SRS.docx` — the Word SRS, for submission (source copy lives in the
  user's Downloads). **It holds the only copies of the 18 use-case and
  prototype images** — `SRS.md` has never had them, so for figures the docx
  is the richer artifact. Synced 2026-09-11: FR-01..FR-10 IDs added to §2.2,
  Executive/Worker/Operator added to §2.3, and UC-10/PR-10 (Leave & Overtime
  Filing and Approval) added as §3.2.1.10/§3.2.2.10 with Figures 19.0/20.0 —
  **both carry a "to be attached" placeholder, since those two diagrams do
  not exist yet**. 2026-09-12: every use-case/prototype subsection heading now
  carries its id inline ("3.2.1.8 (UC-08) ...", "3.2.2.8 (PR-08) ...") so the
  SPMP/STD cross-references actually resolve inside the SRS, and a title-page
  block (version, date, prepared-by, both advisers) was added. Also fixed: the page header read "Software Project
  Management Plan"; §3 opened with the whole §2.5 Assumptions block pasted a
  second time; and Supabase was listed as a dependency (not in §2's fixed
  stack). Still open: §3.5.2 Availability and §3.5.3 Security have swapped
  content (Availability describes access control, Security describes
  usability) — the same defect is flagged in `SRS.md`; fixing it means
  authoring new requirement text, so it was left for the team.
- `/docs/SDD.md` — Software Design Description (all 5 sections written, 26
  figures in `/docs/assets/`, plus 3 corrected variants — see below). §3.1's
  data dictionary is kept current with `backend/database/migrations/` —
  including Phase 2 auth fields (`employee_code`, `email`, `password` on
  Employee; `slug` on Role) and Phase 3 crew deployment state (`status`,
  `deployed_at` on Crew). Figures 1.0, 2.0, and 9.0 (Employee & Workforce
  Management class diagram, Crew Assignment class diagram, and the ERD)
  originally predated this data dictionary; SDD.md now embeds the corrected
  versions directly (`docs/assets/sdd-fig-{1,2,9}-0-...-corrected.svg`), with
  the original stale PNGs kept alongside for reference and a note on each
  explaining what changed.
- `/docs/SDD.docx` — the Word build of the SDD, for submission. Synced from
  `SDD.md` on 2026-09-11: §3.1 data dictionary (Employee 9→26 fields, Role
  `slug`, Crew `status`/`deployed_at`, `certification`/`emergency_contact`
  as `json`), the 7-role RBAC description, Figures 1.0/2.0/9.0 swapped for
  the corrected renders, and a missing Figure 9.0 caption added. **Section 5
  (Human Interface Design) is still an empty heading — not required yet.**
  Regenerate from `SDD.md` whenever the data dictionary changes; it has no
  automated build, so the sync is manual. Known cosmetic gaps left alone:
  the header still reads "Insert Project / System Title Here", the footer
  says "Page Number" instead of a real field, the List of Figures has no
  page numbers, and the List of Tables page numbers (54-66) predate the
  current 28-page layout. A title-page block (version, date, prepared-by,
  both advisers) was added 2026-09-12.
- `/docs/STD.md` + `/docs/STD.docx` — Software Test Document, drafted 2026-09-11.
  Structure follows the school's STD format (1. Introduction / 1.1 System
  Overview / 1.2 Test Approach / 1.3 Definitions · 2. Test Plan / 2.1 Testing
  Tools and Environment / 2.2 Test Case Template · 3. Test Cases · 4.
  Requirements Traceability). Carries the six acceptance test cases named in
  §7 below — TC-01 clock rollback, TC-02 local DB tampering, TC-03 MitM
  signature forgery, TC-04 late foreman override, TC-05 absent foreman
  re-assignment, TC-06 holiday payroll — each traced to an FR/UC in Table 9.0.
  **Actual Result / Pass/Fail / Comments are intentionally blank**: it is a
  test *plan*, to be filled in at execution. Four of the six depend on modules
  not yet built (Phases 5, 7, 8), so they cannot be run yet. The docx is
  generated, not hand-edited — regenerate it from `STD.md` rather than editing
  the Word file, or the two will drift.
- `/docs/CRYPTOGRAPHY_EXPLAINED.md` — plain-English explainer for the 4-layer
  attendance integrity engine, written 2026-09-12 against the actual Phase 5
  code (not a design sketch). Defines every term (TEE, HMAC, ECDSA, monotonic
  clock, StrongBox, ...), walks each layer with the attack it stops *and what
  it doesn't*, maps to STD TC-01/02/03, and carries a limitations section plus
  likely defense Q&A. Two points in it are load-bearing and easy to get wrong
  when explaining the project: the system is **tamper-evident, not
  tamper-proof** (it cannot stop a foreman lying at tap time, only detect
  later edits), and `HRIS_REQUIRE_HARDWARE_KEYS` defaults to **false**, so the
  hardware-backing guarantee is documented but not enforced until production.
  Update it alongside any crypto-engine change.
- `/docs/HRIS_ERD.drawio` — entity-relationship diagram (editable in draw.io)
- `/docs/HRIS_ERD_reference.md` — ERD design/formatting notes

> ⚠️ Keep this list accurate. When a doc gets replaced with a newer version,
> update the filename/date here so Claude Code doesn't work off stale specs.

## 7. Working conventions

- PHP: PSR-12 coding standard.
- JS/React/React Native: Airbnb Style Guide.
- Any change to crypto engine or payroll logic **must** include unit tests
  (see SPMP §3.3.4 Quality Control Plan, and STD test cases TC-01–TC-06:
  clock rollback attack, DB tampering, MitM signature forgery, late foreman
  override, absent foreman re-assignment, holiday payroll computation).
- Before implementing a module, check its Use Case in the SRS and its task
  breakdown in the SPMP Work Activities (WBS) section.

## 8. Instructions for Claude Code

- Read `/docs/SRS.md` and `/docs/SPMP.md` before starting work on a new module.
- Confirm which Use Case (UC-xx) or Functional Requirement (FR-xx) a task maps to
  before writing code — ask if unclear rather than guessing.
- Do not introduce new libraries/frameworks outside the fixed stack above without
  flagging it first — the stack is a hard constraint from the SPMP.
