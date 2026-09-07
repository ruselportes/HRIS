# HRIS Entity-Relationship Diagram — Reference

This note accompanies `HRIS_ERD.drawio` (delivered to Rus in chat, latest version). History: an earlier version used a self-invented 17-table schema before the actual SDD was shared; it was rebuilt to match `HRIS_Software_Design_Description.docx` (v1.0, Sept 3 2026) Section 3.1's 13-table data dictionary; the visual style was then redrawn a second time to match a reference screenshot Rus provided (classic crow's-foot ERD look).

## Visual format (matches Rus's reference)
- Gray header bar per table, bold centered table name (`tbl_xxx`), uniform across all tables (no per-module color coding).
- Body is a 2-column grid: narrow left column shows `PK` / `FK` (blank otherwise), wider right column shows the field name only (no data type shown, matching the reference).
- PK field name is bold + underlined; a bold black divider line sits directly under the PK row separating it from the FK/attribute rows below; FK field names are bold; regular attributes are plain.
- Relationships use classic crow's-foot notation: double-tick (`ERmandOne`) = mandatory one, circle+fork (`ERzeroToMany`) = zero-or-many, circle+tick (`ERzeroToOne`) = zero-or-one. Lines are black, sharp right-angle corners (not rounded), routed via `entityRelationEdgeStyle`.
- Layout is still hub-and-spoke (Employee is the most-referenced table, centered; Site/Crew/Crew Assignment to its left; Attendance/Sync/Crypto, Payroll/Payroll Detail, and Leave/Overtime/Audit Log fanned out to its right) with fixed, staggered connection points per side so lines don't bunch up — this is what fixed the original "connections are so messy" complaint.

## Entities (13 tables, per SDD Section 3.1) and relationships

Field-level breakdown below transcribed from the ERD diagram Rus pasted in chat on 2026-09-07; the source `HRIS_ERD.drawio` file was added to this folder the same day and confirmed to match (all 13 `tbl_*` table names verified against the file). Employee.certification and Employee.emergency_contact were added back in (present in the data dictionary's Table 2.0 but missing from the SDD's existing Figure 9.0 image). No separate `users`/login table — RBAC runs off `Employee.role_id` → `Role` directly.

| Table | PK | FK(s) | Other columns |
|---|---|---|---|
| `tbl_role` | role_id | — | role_name, description |
| `tbl_site` | site_id | — | site_name, location, status |
| `tbl_employee` | employee_id | role_id → tbl_role | first_name, last_name, trade_skill, daily_rate, certification, emergency_contact, employment_status |
| `tbl_crew` | crew_id | site_id → tbl_site, foreman_id → tbl_employee | crew_name |
| `tbl_crew_assignment` | assignment_id | crew_id → tbl_crew, employee_id → tbl_employee | date_assigned, status |
| `tbl_attendance` | attendance_id | employee_id → tbl_employee, crew_id → tbl_crew | time_in, time_out, monotonic_timestamp, sync_status, override_flag |
| `tbl_attendance_sync_queue` | queue_id | attendance_id → tbl_attendance | device_id, queued_at, synced_at, sync_status |
| `tbl_crypto_signature` | signature_id | attendance_id → tbl_attendance | hmac_hash, prev_hash, ecdsa_signature, verified |
| `tbl_payroll` | payroll_id | employee_id → tbl_employee | pay_period_start, pay_period_end, gross_pay, net_pay, status |
| `tbl_payroll_detail` | detail_id | payroll_id → tbl_payroll | regular_hours, overtime_hours, night_diff_hours, rest_day_hours, holiday_hours, deductions |
| `tbl_leave_request` | leave_id | employee_id → tbl_employee, approved_by → tbl_employee | leave_type, date_from, date_to, status |
| `tbl_overtime_request` | ot_id | employee_id → tbl_employee, approved_by → tbl_employee | ot_date, hours_requested, status |
| `tbl_audit_log` | audit_id | actor_id → tbl_employee | action_type, description, timestamp |

Self-referencing FKs to note when writing migrations (all point back to `tbl_employee`): `tbl_crew.foreman_id`, `tbl_leave_request.approved_by`, `tbl_overtime_request.approved_by`, `tbl_audit_log.actor_id`.

This diagram is a logical/structural ERD, not exhaustive of every UI field — e.g. the Employee Records design prototype (`docs/prototypes/HRIS Employee Records.dc.html`) needs additional descriptive columns on `tbl_employee` (mobile, address, date_of_birth, civil_status, TIN/SSS/PhilHealth/Pag-IBIG, date_hired, cost_centre, etc.) that aren't drawn here but don't contradict this structure either. Treat this ERD as authoritative for table names, keys, and relationships; treat the prototypes as the source for additional non-relational attribute detail layered on top.

## File
`HRIS_ERD.drawio` (in this same `docs/` folder) — open in draw.io / diagrams.net (File → Open), fully editable.
