# Software Test Document (STD)

*for*

**Human Resource Information System (HRIS)**
**for Arcenas Development Corporation**

- **Document Version:** 1.0
- **Date:** September 11, 2026
- **Prepared By:** Jay Mark A. Reños, Liza Mae C. Sugala, Rusel R. Portes, Efren S. Cabudbud Jr., CarlVey Sente, Rayla G. Lanaza
- **Technical Adviser:** Eric Bulala
- **Subject Adviser:** Engr. Clark Kevin V. Villamor — Head, College of Computer Studies

---

## List of Tables

| Table | Description |
|---|---|
| 1.0 | Definition, Acronyms, and Abbreviations |
| 2.0 | Test Case Template |
| 3.0 | TC-01 Clock Rollback Attack Test Case |
| 4.0 | TC-02 Local Database Tampering Test Case |
| 5.0 | TC-03 Man-in-the-Middle Signature Forgery Test Case |
| 6.0 | TC-04 Late Foreman Override Test Case |
| 7.0 | TC-05 Absent Foreman Re-assignment Test Case |
| 8.0 | TC-06 Holiday Payroll Computation Test Case |
| 9.0 | Requirements Traceability Matrix |

---

## 1. Introduction

This document contains the test plan for the Human Resource Information System (HRIS) for Arcenas Development Corporation. It defines the approach, environment, and specific test cases used to verify that the system satisfies the functional requirements (FR-01 to FR-10) and use cases (UC-01 to UC-10) defined in the SRS, and behaves as described in the SDD.

### 1.1. System Overview

The HRIS is a web-based and mobile-enabled system that centralizes employee records, crew deployment, attendance, leave, and payroll for a construction company operating across multiple project sites. The web portal is built with React.js against a Laravel REST API and a MySQL 8.0 database. The mobile attendance application is built with React Native and stores roll-call records locally in an encrypted SQLite (SQLCipher) database so that site foremen can record attendance at remote sites with no internet connectivity.

Because attendance data drives payroll, the system's distinguishing feature is a four-layer cryptographic integrity engine: a hardware monotonic clock reading captured at the moment of logging, an HMAC-SHA256 hash chain linking each record to the previous one, an ECDSA P-256 signature generated inside the device's Trusted Execution Environment (Android Keystore) or Secure Enclave, and server-side verification of both the chain and the signature before any record is committed. Testing therefore places particular emphasis on the tamper-resistance of attendance timestamps.

### 1.2. Test Approach

Testing for the HRIS is carried out at four levels:

- **Unit Testing** — individual services, policies, and pure-logic functions are tested in isolation. Backend units are tested with PHPUnit under Laravel; mobile and web units are tested with Jest. Per the SPMP Quality Control Plan (§3.3.4), any change to the cryptographic engine or the payroll computation logic must ship with unit tests.
- **Integration Testing** — the mobile application, the Laravel REST API, and the MySQL database are exercised together, with particular attention to the offline-to-online synchronization path and to server-side signature verification.
- **System Testing** — the six test cases in Section 3 are executed end to end against a fully deployed environment, including the deliberate attack scenarios (TC-01 to TC-03) that attempt to defeat the integrity engine.
- **User Acceptance Testing** — the designated Arcenas Development Corporation stakeholder exercises the system against real workflows and signs off on acceptance.

Test cases TC-01 to TC-06 are the acceptance gate defined in the SPMP Product Acceptance Plan (§4.4): all six must pass before the system is considered accepted.

### 1.3. Definition, Acronyms, and Abbreviations

| Term | Definition |
|---|---|
| HRIS | Human Resource Information System — the system under test. |
| Test Case | A set of conditions, inputs, and expected results under which a tester determines whether a feature satisfies its requirement. |
| Test Plan | A document describing the scope, approach, resources, and schedule of testing activities. |
| Monotonic Clock | A hardware-backed counter that only increases and is unaffected by changes to the device's wall-clock setting. Read via `elapsedRealtime` on Android and `mach_continuous_time` on iOS. |
| HMAC-SHA256 | Keyed-Hash Message Authentication Code using SHA-256, used to chain each attendance record to the one before it. |
| Hash Chain | A sequence of records in which each entry stores the hash of the previous entry, so that altering any record invalidates every record after it. |
| ECDSA P-256 | Elliptic Curve Digital Signature Algorithm over the NIST P-256 curve, used to sign attendance payloads. |
| TEE | Trusted Execution Environment — an isolated processor region where private keys are generated and used but cannot be exported. Android Keystore on Android; Secure Enclave on iOS. |
| SQLCipher | An extension to SQLite providing transparent AES-256 encryption of the local mobile database. |
| MitM | Man-in-the-Middle — an attack in which a third party intercepts and may alter traffic between the mobile application and the server. |
| Sync Queue | The local table holding attendance records captured offline that are awaiting transmission to the central server. |
| RBAC | Role-Based Access Control — permission enforcement driven by `Employee.role_id`. |
| Regression Test | A re-run of previously passing tests to confirm that a change has not broken existing behavior. |
| UAT | User Acceptance Testing — validation performed by the client stakeholder. |

*Table 1.0 Definition, Acronyms, and Abbreviations*

---

## 2. Test Plan

The HRIS test plan focuses on four areas: correctness of the HR and payroll data flows, the ability of the mobile application to operate and preserve data without connectivity, the tamper-resistance of attendance records, and the enforcement of role-based access. Functional behavior is verified against the use cases in the SRS; integrity behavior is verified by actively attempting the attacks the system is designed to resist.

### 2.1. Testing Tools and Environment

The HRIS consists of a web application, a mobile application, and a backend API and database. The test environment covers all three, plus the tooling required to simulate offline conditions and to attempt interception of network traffic.

**Tools and Components:**

- Laravel 13 with PHPUnit — backend unit and feature tests
- Jest with React Native Testing Library — mobile and web unit tests
- Postman — manual API endpoint testing
- Android Debug Bridge (`adb`) — device control, log capture, and clock manipulation
- DB Browser for SQLite / SQLCipher CLI — inspection and deliberate tampering of the local mobile database
- An intercepting HTTPS proxy — payload modification for the man-in-the-middle test case
- MySQL 8.0 client — inspection of server-side attendance, signature ledger, and audit tables

**Environment:**

- Local Laravel API served via Docker or XAMPP, backed by MySQL 8.0
- React.js web portal running against the same API
- Physical Android device running Android 10 or higher, used for all mobile test cases
- A Wi-Fi network that can be disabled on demand to simulate a remote site with no connectivity
- Test data seeded from `RoleSeeder` and a sample set of employees, sites, and crews

**Testers:**

- Efren S. Cabudbud Jr. (Database & QA Lead) — primary tester, test case execution and defect logging
- Rusel R. Portes (Lead Developer) — defect resolution and re-verification
- Jay Mark A. Reños (Project Manager) — review and approval of test results
- Designated Arcenas Development Corporation stakeholder — user acceptance testing

### 2.2. Test Case Template

The template used for designing each test case is shown in the table below.

| Field | Description |
|---|---|
| Test Case ID: | |
| Date: | |
| Objective: | |
| Hardware Components Involved: | |
| Software Components Involved: | |
| Test Setup: | |
| Testing Procedure: | |
| Expected Result: | |
| Actual Result: | |
| Pass/Fail: | |
| Comments/Observation: | |

*Table 2.0 Test Case Template*

---

## 3. Test Cases

### 3.1. Clock Rollback Attack Test Case

| Field | Detail |
|---|---|
| **Test Case ID:** | TC-01 |
| **Date:** | |
| **Objective:** | To verify that setting the mobile device's system clock backwards does not alter the effective time recorded for an attendance entry, and that the resulting record is flagged on server-side verification. |
| **Hardware Components Involved:** | Android test device (Android 10+), API server, MySQL database server |
| **Software Components Involved:** | React Native mobile application, MonotonicClockService (`elapsedRealtime`), HashChainBuilder, Laravel SignatureVerifier, `tbl_attendance`, `tbl_crypto_signature`, `tbl_audit_log` |
| **Test Setup:** | Foreman signed in on the mobile application with a crew roster cached locally. Device placed in airplane mode to simulate a remote site. At least one attendance entry already recorded so that a previous hash exists in the chain. |
| **Testing Procedure:** | 1. Record a roll-call entry for Worker A and note the stored `monotonic_timestamp` and `time_in`. 2. Set the device system clock back by two hours in Android Settings. 3. Record a roll-call entry for Worker B. 4. Restore connectivity and allow the background sync engine to upload both entries. 5. Inspect `monotonic_timestamp` and `time_in` for both rows, and the corresponding `tbl_crypto_signature` entries, on the server. |
| **Expected Result:** | The monotonic counter is unaffected by the wall-clock change, so Worker B's `monotonic_timestamp` is strictly greater than Worker A's. The server detects that the wall-clock `time_in` regressed while the monotonic counter advanced, rejects or flags Worker B's record rather than trusting the rolled-back time, records `verified = false` in the signature ledger, and writes an entry to `tbl_audit_log`. The hash chain remains continuous and no falsified time is committed as the effective attendance time. |
| **Actual Result:** | |
| **Pass/Fail:** | |
| **Comments/Observation:** | |

*Table 3.0 TC-01 Clock Rollback Attack Test Case*

### 3.2. Local Database Tampering Test Case

| Field | Detail |
|---|---|
| **Test Case ID:** | TC-02 |
| **Date:** | |
| **Objective:** | To verify that directly editing an attendance row in the encrypted local database breaks the HMAC-SHA256 hash chain and that the altered record is rejected during synchronization. |
| **Hardware Components Involved:** | Android test device with debugging enabled, API server, MySQL database server |
| **Software Components Involved:** | SQLite with SQLCipher local store, HashChainBuilder, background sync engine, Laravel chain verification service, `tbl_attendance`, `tbl_crypto_signature` |
| **Test Setup:** | Device offline. Three roll-call entries recorded and held in the local sync queue. The SQLCipher key is made available to the tester for the purposes of this test only. |
| **Testing Procedure:** | 1. Record three roll-call entries offline and note each row's `hmac_hash` and `prev_hash`. 2. Open the local database with a SQLCipher-capable client and alter the `time_in` of the second row (for example from 08:15 to 07:00) without recomputing any hashes. 3. Restore connectivity and allow the sync engine to transmit all three rows. 4. Inspect the server's verification result for each row and the contents of `tbl_attendance`. |
| **Expected Result:** | The HMAC recomputed over row 2's payload no longer matches its stored `hmac_hash`, and the linkage from row 2 to row 3 is broken. The server accepts row 1, rejects rows 2 and 3, and records `verified = false` for the rejected rows. No tampered value is committed to `tbl_attendance`, and an audit entry is written identifying the point at which the chain broke. |
| **Actual Result:** | |
| **Pass/Fail:** | |
| **Comments/Observation:** | |

*Table 4.0 TC-02 Local Database Tampering Test Case*

### 3.3. Man-in-the-Middle Signature Forgery Test Case

| Field | Detail |
|---|---|
| **Test Case ID:** | TC-03 |
| **Date:** | |
| **Objective:** | To verify that an attendance payload altered in transit fails ECDSA P-256 signature verification, and that a signature produced outside the device's Trusted Execution Environment is not accepted. |
| **Hardware Components Involved:** | Android test device, proxy workstation, API server, MySQL database server |
| **Software Components Involved:** | TEESigner (Android Keystore), Laravel SignatureVerifier, intercepting HTTPS proxy, `tbl_crypto_signature`, `tbl_audit_log` |
| **Test Setup:** | Device bound to the system with a keypair generated inside the TEE, whose public key is registered on the server. The proxy's certificate authority is trusted on the test device for the duration of this test only, so that HTTPS traffic can be intercepted. |
| **Testing Procedure:** | 1. Record an attendance entry and allow synchronization to begin. 2. Intercept the sync request and alter a field in the payload — for example `employee_id` or `time_in` — leaving `ecdsa_signature` unchanged, then forward the request. 3. Observe the API response and the server-side tables. 4. Repeat the interception, this time replacing the signature with one generated from a keypair created outside the TEE. 5. Observe the API response again. |
| **Expected Result:** | In both attempts the server rejects the record. In the first, the signature no longer matches the modified payload; in the second, the signature does not verify against the registered public key for that device. No row is committed to `tbl_attendance`, `verified = false` is recorded, and an audit entry is written for each rejected attempt. The private key is not exportable from the TEE, so a valid signature cannot be produced off-device. |
| **Actual Result:** | |
| **Pass/Fail:** | |
| **Comments/Observation:** | |

*Table 5.0 TC-03 Man-in-the-Middle Signature Forgery Test Case*

### 3.4. Late Foreman Override Test Case

| Field | Detail |
|---|---|
| **Test Case ID:** | TC-04 |
| **Date:** | |
| **Objective:** | To verify that when a site foreman arrives after the start of the shift, the override workflow credits the crew from the 7:00 AM shift start rather than the time of logging, and that the override is recorded in the audit log. |
| **Hardware Components Involved:** | Android test device, API server, MySQL database server, workstation for the web portal |
| **Software Components Involved:** | React Native roll-call screen, Late Foreman Override workflow, Audit Log service, Late Override Audit web screen, `tbl_attendance`, `tbl_audit_log` |
| **Test Setup:** | A crew deployed to an active site with a designated foreman. System time set to 09:20, which is after the 7:00 AM shift start. Workers A, B, and C on the crew roster. |
| **Testing Procedure:** | 1. Sign in to the mobile application as the Site Foreman at 09:20. 2. Open the Roll Call screen and confirm that the application detects the late start and offers the override. 3. Apply the Late Foreman Override for the crew. 4. Mark Workers A, B, and C as Present. 5. Synchronize, then review the attendance records and the audit log in the web portal. |
| **Expected Result:** | The credited workers' `time_in` is recorded as 07:00 rather than 09:20, and `override_flag` is set to true on those rows. One `tbl_audit_log` entry is written per override with `action_type` of `FOREMAN_LATE_OVERRIDE`, the acting foreman as `actor_id`, and a timestamp reflecting the actual time of the action (09:20), so that the credited time and the real time of entry are both recoverable. The override is visible on the Late Override Audit screen. |
| **Actual Result:** | |
| **Pass/Fail:** | |
| **Comments/Observation:** | |

*Table 6.0 TC-04 Late Foreman Override Test Case*

### 3.5. Absent Foreman Re-assignment Test Case

| Field | Detail |
|---|---|
| **Test Case ID:** | TC-05 |
| **Date:** | |
| **Objective:** | To verify that a crew whose foreman is absent can be re-assigned to an acting foreman in a single action, that the acting foreman can immediately record attendance, and that the original foreman loses access to that crew. |
| **Hardware Components Involved:** | Two Android test devices, workstation for the web portal, API server, MySQL database server |
| **Software Components Involved:** | Acting Foreman Reassignment web screen, crew and foreman-scoped roster API, RBAC policies, Audit Log service, `tbl_crew`, `tbl_crew_assignment`, `tbl_audit_log` |
| **Test Setup:** | Crew X deployed to an active site with Foreman F1 designated. Foreman F2 available and holding the Site Foreman role. A Site Engineer signed in to the web portal. |
| **Testing Procedure:** | 1. As the Site Engineer, open the Acting Foreman Reassignment screen and select Crew X. 2. Re-assign Crew X to Foreman F2 in a single action. 3. Sign in to the mobile application as F2 and refresh the crew roster. 4. Record roll call for Crew X as F2. 5. Attempt to fetch the roster for and record roll call for Crew X as F1. 6. Review the audit log in the web portal. |
| **Expected Result:** | `tbl_crew.foreman_id` for Crew X is updated to F2. F2's foreman-scoped roster returns Crew X and attendance recorded by F2 is accepted. F1's request for Crew X is refused by the roster policy. A `tbl_audit_log` entry records the re-assignment with the Site Engineer as `actor_id` and a timestamp. The prior assignment history is preserved through `tbl_crew_assignment.status` rather than being deleted, so the change remains auditable. |
| **Actual Result:** | |
| **Pass/Fail:** | |
| **Comments/Observation:** | |

*Table 7.0 TC-05 Absent Foreman Re-assignment Test Case*

### 3.6. Holiday Payroll Computation Test Case

| Field | Detail |
|---|---|
| **Test Case ID:** | TC-06 |
| **Date:** | |
| **Objective:** | To verify that the payroll engine applies the Philippine Labor Code premium rates used by this system — overtime at 1.25x, night differential at 1.10x, rest day at 1.30x, and regular holiday at 2.00x — and that the computed gross pay matches a manual calculation. |
| **Hardware Components Involved:** | API server, MySQL database server, workstation for the web portal |
| **Software Components Involved:** | PayrollEngine, RateCalculator, Payroll Run web screen, `tbl_attendance`, `tbl_payroll`, `tbl_payroll_detail` |
| **Test Setup:** | One employee with a `daily_rate` of ₱600.00, equivalent to ₱75.00 per hour over an eight-hour day. Within a single pay period, verified and synced attendance records for: 8 regular hours; 2 overtime hours on a regular day; 2 night differential hours worked between 10:00 PM and midnight; 8 hours worked on a rest day; and 8 hours worked on a declared regular holiday. Each premium is exercised on a separate day so that the multipliers can be verified independently. |
| **Testing Procedure:** | 1. Confirm that all attendance rows for the period carry `verified = true` and are synced. 2. Open the Payroll Run screen and run payroll for the pay period. 3. Inspect `tbl_payroll_detail` and confirm the hour buckets: `regular_hours` = 8, `overtime_hours` = 2, `night_diff_hours` = 2, `rest_day_hours` = 8, `holiday_hours` = 8. 4. Compare the computed gross pay against the manual calculation. 5. Verify that deductions are applied and that net pay equals gross pay less deductions. |
| **Expected Result:** | The hour buckets match those listed above, and each premium computes as: regular 8 × ₱75.00 = ₱600.00; overtime 2 × ₱75.00 × 1.25 = ₱187.50; night differential 2 × ₱75.00 × 1.10 = ₱165.00; rest day 8 × ₱75.00 × 1.30 = ₱780.00; regular holiday 8 × ₱75.00 × 2.00 = ₱1,200.00. Computed gross pay is ₱2,932.50, matching the manual calculation to the centavo. `tbl_payroll.status` remains Draft until explicitly approved, and net pay equals gross pay less the applied deductions. |
| **Actual Result:** | |
| **Pass/Fail:** | |
| **Comments/Observation:** | |

*Table 8.0 TC-06 Holiday Payroll Computation Test Case*

---

## 4. Requirements Traceability

Each test case traces back to the functional requirements and use cases it verifies, as defined in the SRS (§2.2 and §3.2.1).

| Test Case | Verifies | Related Use Case | SDD Reference |
|---|---|---|---|
| TC-01 Clock Rollback Attack | FR-10 Cryptographic Validation | UC-04 | §2.1.4, §4.3 |
| TC-02 Local Database Tampering | FR-05 Offline Attendance, FR-10 Cryptographic Validation | UC-04 | §2.1.3, §2.1.4, §3.1.8 |
| TC-03 MitM Signature Forgery | FR-10 Cryptographic Validation, FR-06 Automatic Data Synchronization | UC-04 | §2.1.4, §4.3, §4.4 |
| TC-04 Late Foreman Override | FR-03 Attendance Management, FR-04 Mobile Attendance Recording | UC-05 | §3.1.6, §3.1.13 |
| TC-05 Absent Foreman Re-assignment | FR-02 Employee Information Management, FR-03 Attendance Management | UC-06 | §2.1.2, §3.1.5, §3.1.13 |
| TC-06 Holiday Payroll Computation | FR-08 Payroll Management | UC-08 | §2.1.6, §3.1.9, §3.1.10, §4.5 |

*Table 9.0 Requirements Traceability Matrix*

> **Note:** TC-01, TC-02, and TC-03 exercise the Cryptographic Attendance Integrity Engine, and TC-06 exercises the Payroll Engine. Both are scheduled for later development phases (see `docs/TASKS.md`, Phases 5 and 8), so these four cases are specified here in advance and are to be executed once those modules are implemented. TC-04 and TC-05 depend on the Foreman Edge Case Handling module (Phase 7).
