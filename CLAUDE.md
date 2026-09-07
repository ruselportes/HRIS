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

## 4. Database (13-table schema)

Per SDD Section 3.1 data dictionary (see `/docs/HRIS_ERD.drawio` and
`/docs/HRIS_ERD_reference.md` for the full ERD):

`Role`, `Employee`, `Site`, `Crew`, `Crew Assignment`, `Attendance`,
`Attendance Sync Queue`, `Crypto Signature Ledger`, `Payroll`, `Payroll Detail`,
`Leave Request`, `Overtime Request`, `Audit Log`.

- RBAC runs off `Employee.role_id → Role` — **no separate users/login table.**
- `Employee.certification` and `Employee.emergency_contact` fields exist per the
  data dictionary (may be missing from older SDD figures — data dictionary wins).

## 5. User roles

- **HR Personnel** — employee records, attendance, leave, payroll
- **Site Foremen** — mobile attendance logging (primary offline users)
- **Site Engineers / Construction Managers** — crew assignment, site monitoring
- **System Administrator** — accounts, permissions, config
- **Executives** — analytics dashboards, audit review

## 6. Where the docs live

- `/docs/SPMP.md` — Software Project Management Plan
- `/docs/SRS.md` — Software Requirements Specification
- `/docs/SDD.docx` — Software Design Description *(add once finalized)*
- `/docs/STD.docx` — Software Test Document *(add once finalized)*
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
