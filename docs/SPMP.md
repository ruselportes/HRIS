# Software Project Management Plan (SPMP)

*for*

**Human Resource Information System (HRIS)**
**for Arcenas Development Corporation**

- **Document Version:** 1.0
- **Date:** July 27, 2026
- **Prepared By:** Jay Mark A. Reños, Liza Mae C. Sugala, Rusel R. Portes, Efren S. Cabudbud Jr., CarlVey Sente, Rayla G. Lanaza
- **Technical Adviser:** Eric Bulala
- **Subject Adviser:** Engr. Clark Kevin V. Villamor — Head, College of Computer Studies

---

## Section 1: Overview

This Software Project Management Plan (SPMP) defines how the Human Resource Information System (HRIS) for Arcenas Development Corporation will be planned, organized, executed, monitored, and delivered. It is the governing management document for the project: it identifies the deliverables and their acceptance criteria, assigns roles and responsibilities across the development team, establishes the schedule and the work breakdown structure, and sets out the plans by which requirements, schedule, budget, quality, and risk are controlled for the duration of development. Where the Software Requirements Specification (SRS) states what the system must do, and the Software Design Description (SDD) states how it is structured, this document states how the work of building it is managed and tracked.

The project exists to solve a specific operational problem. Arcenas Development Corporation currently records the daily attendance of its construction crews on paper at each project site. Many of these sites are remote and have little or no internet connectivity, so attendance sheets are gathered and encoded manually, often several days after the work was performed. This delays payroll preparation, introduces transcription errors, and leaves recorded working time open to alteration — whether accidental or deliberate — with no dependable means of detecting it afterwards. Payroll must also apply the premium rates mandated by the Philippine Labor Code, which is difficult to compute consistently by hand across several sites and pay periods.

The HRIS addresses these problems by capturing attendance on a mobile application that continues to work without connectivity, protecting every record with hardware-backed cryptographic signing so that tampering can be detected, synchronizing records automatically once a connection is restored, and computing payroll directly from verified attendance data. This plan covers the management of that work from requirements gathering, through development and testing, to the capstone defense.

### 1.1. Project Summary

#### 1.1.1. Purpose, Scope, and Objectives

**Purpose**

The Human Resource Information System (HRIS) for Arcenas Development Corporation is designed to digitize, centralize, and automate the human resource and payroll management operations of the company. The system addresses the challenge of accurately recording daily attendance for construction workers deployed across multiple sites — including remote locations where internet access is unavailable — while ensuring all attendance data is protected against tampering and is compliant with the Philippine Labor Code for payroll computation.

**Scope**

The HRIS for Arcenas Development Corporation covers the full scope of HR and payroll management for the company's construction workforce. The system is composed of the following components:

- **Employee and Workforce Management:** The system manages complete employee records including personal information, trade skills, daily pay rates, skill certifications, emergency contacts, and employment status. It also handles crew formation and site deployment assignments managed by Site Engineers.
- **Attendance Management:** The system records daily worker attendance through two modes — (a) the central web portal for on-site or office-based logging, and (b) an offline-capable mobile application for Site Foremen at remote construction sites where internet connectivity is unavailable. Attendance data captured offline is stored locally on the foreman's mobile device and automatically synced to the central system once connectivity is restored.
- **Cryptographic Attendance Integrity:** To prevent timestamp manipulation on field devices, the mobile application employs a 4-layer cryptographic defense: hardware monotonic clock capture (`elapsedRealtime` on Android, `mach_continuous_time` on iOS), HMAC-SHA256 cryptographic hash chaining, and ECDSA P-256 digital signing via the device's built-in hardware security chip (Android Keystore TEE / iOS Secure Enclave).
- **Payroll Processing:** The system automates payroll computation for all employees in compliance with the Philippine Labor Code — including regular pay, overtime (1.25x), night differential (1.10x), rest day (1.30x), and holiday (2.0x) rates. Payroll is based solely on verified and synced attendance records.
- **Leave and Overtime Management:** Employees and supervisors can file and approve leave requests and overtime applications through a multi-tier approval workflow within the web portal.
- **Audit and Compliance:** The system logs all attendance override events (e.g., late foreman overrides, crew re-assignments, retroactive attendance recovery) and provides executive-level dashboards for labor cost analytics, site punctuality tracking, and audit event review.

**Objectives**

- Digitize and centralize HR and payroll operations for Arcenas Development Corporation.
- Enable site foremen to log crew attendance reliably even in areas with no cellular or internet signal.
- Prevent attendance timestamp manipulation through cryptographic and hardware-level defenses.
- Handle real-world construction site edge cases: late foremen, absent foremen, remote sites with no fixed guard post.
- Automate payroll computation in compliance with the Philippine Labor Code (OT 1.25x, Night Diff 1.10x, Rest Day 1.30x, Holiday 2.0x).
- Provide executives with real-time labor cost analytics and audit event dashboards.

#### 1.1.2. Assumptions and Constraints

**Assumptions**

- Site Foremen are each assigned an Android or iOS smartphone capable of running the React Native mobile application.
- Modern Android and iOS smartphones have built-in hardware security chips (Android Keystore TEE on Android, Secure Enclave on iOS) that enable cryptographic signing without requiring specialized external hardware.
- Construction sites experience intermittent or zero internet connectivity during active shifts, but regain connectivity at end-of-day or when foremen return to areas with signal.
- Arcenas Development Corporation will designate HR Personnel, Site Engineers, and Executives to be trained on the web portal prior to deployment.
- A staging server environment will be available for integration testing prior to production deployment.

**Constraints**

- Fixed technology stack: Laravel 8+ (PHP), React.js, MySQL 8.0, React Native, SQLite with SQLCipher (AES-256), HMAC-SHA256, ECDSA P-256.
- System development is bound by the capstone academic timeline and submission deadlines.
- Mobile application targets Android as primary platform, iOS as secondary.
- Philippine Labor Code regulations (Articles 83, 86, 87, 93, 94) strictly govern all payroll computation logic.
- Budget is limited to available academic and personal resources; no paid cloud infrastructure required for capstone defense.

#### 1.1.3. Project Deliverables

| # | System Deliverable | Description |
|---|---|---|
| 1 | Employee & Workforce Management Module | A fully functional module for managing employee profiles, trade skills, certifications, daily pay rates, and employment status accessible via the web portal. |
| 2 | Crew Assignment & Site Deployment Module | Allows Site Engineers to organize workers into crews, assign them to project sites, and designate responsible foremen via the web portal. |
| 3 | Offline-Capable Mobile Attendance Checklist | A React Native mobile application that allows Site Foremen to record crew attendance via a digital tap-based checklist without internet connectivity. |
| 4 | Cryptographic Attendance Integrity Engine | A 4-layer security engine: monotonic clock capture, HMAC-SHA256 hash chaining, TEE/Secure Enclave ECDSA payload signing, and server-side verification. |
| 5 | Automated Background Sync Engine | A background service on the mobile app that automatically detects internet connectivity and transmits queued offline attendance data to the central HRIS server. |
| 6 | Foreman Edge Case Handling System | System logic covering late foreman override (default 7:00 AM shift credit with audit flag), 1-click absent foreman crew re-assignment, and retroactive crew recovery sign-off workflow. |
| 7 | Philippine Labor Code Payroll Engine | Automated payroll computation engine calculating gross and net pay including OT (1.25x), Night Differential (1.10x), Rest Day (1.30x), Holiday (2.0x), deductions, and statutory benefits. |
| 8 | Leave & Overtime Filing Workflow | A multi-tier leave and overtime application and approval workflow accessible to all user roles via the web portal. |
| 9 | Executive Compliance & Analytics Dashboard | Real-time dashboards displaying labor cost breakdowns, site attendance punctuality rates, and audit override event logs for company executives and HR managers. |

#### 1.1.4. Schedule and Project Summary

| Phase | Key Activities | Milestone |
|---|---|---|
| Phase 1: Requirements Gathering | Stakeholder interviews, SRS drafting, use case identification | SRS Approved |
| Phase 2: System Design | Architecture design, ERD, SDD, UI/UX prototyping | SDD Approved |
| Phase 3: Core Web Development | Laravel API, React.js frontend, MySQL schema, RBAC | Web Portal Functional |
| Phase 4: Mobile Development | React Native app, offline SQLite storage, background sync engine | Mobile App Functional |
| Phase 5: Cryptographic Integration | Monotonic clock capture, HMAC hash chaining, TEE payload signing | Crypto Engine Functional |
| Phase 6: Integration & Testing | End-to-end testing, STD execution, UAT with stakeholders | All Test Cases Passed |
| Phase 7: Documentation Finalization | SPMP, SRS, SDD, STD review and final packaging | All Documents Submitted |
| Phase 8: Capstone Defense | System demonstration, Q&A defense, project handoff | Defense Completed |

### 1.2. Evolution of Plan

This SPMP is a living document and shall be reviewed and updated when:

- Significant changes to project scope or system features are requested by the adviser or stakeholders.
- Critical schedule delays exceeding one week require milestone re-alignment.
- Team member role changes or staffing adjustments occur.
- Technical constraints are discovered that were not previously identified.

All plan revisions shall be documented with a new version number, date, and description of changes.

### 1.3. Definitions, Acronyms, and Abbreviations

| Term | Definition |
|---|---|
| **HRIS** | Human Resource Information System — the central web-based system for HR and payroll management. |
| **SPMP** | Software Project Management Plan — this document. |
| **SRS** | Software Requirements Specification. |
| **SDD** | Software Design Description. |
| **STD** | Software Test Document. |
| **Mobile App** | The React Native mobile attendance application used by site foremen. |
| **Offline-Capable** | The mobile app's ability to function and record attendance without internet connectivity, storing data locally until sync. |
| **TEE** | Trusted Execution Environment — hardware security chip in Android devices (Android Keystore) for cryptographic key generation that cannot be extracted by software. |
| **Secure Enclave** | Apple's equivalent of TEE on iOS devices. |
| **ECDSA** | Elliptic Curve Digital Signature Algorithm — used to sign attendance batch payloads inside the device TEE/Secure Enclave. |
| **HMAC-SHA256** | Hash-based Message Authentication Code using SHA-256 — creates the cryptographic hash chain linking attendance records. |
| **Monotonic Clock** | A hardware clock (elapsedRealtime on Android, mach_continuous_time on iOS) that counts time since device boot and CANNOT be altered by manual clock changes. |
| **SQLCipher** | Open-source SQLite extension providing AES-256 transparent database encryption for local mobile storage. |
| **RBAC** | Role-Based Access Control — restricts system access by user role (Admin, HR, Engineer, Foreman). |
| **Foreman Late Override** | System flag (`FOREMAN_LATE_OVERRIDE`) applied when a foreman logs attendance after shift start time, crediting workers from standard shift start. |
| **Retroactive Recovery** | Workflow allowing Site Engineers to review and sign off on crew attendance for days where no logging occurred. |
| **Background Sync** | Automatic transmission of queued offline attendance data to the central HRIS once mobile internet connectivity is detected. |
| **Philippine Labor Code** | Republic Act 442 — governs payroll computation rules including OT (1.25x), Night Differential (1.10x), Rest Day (1.30x), and Holiday (2.0x) pay rates. |

### 1.4. References

- IEEE Std 1058-1998: Standard for Software Project Management Plans.
- Republic Act 442: Philippine Labor Code — Articles 83, 86, 87, 93, and 94.
- Arcenas Development Corporation System Requirements and Business Rules.
- React Native Documentation — https://reactnative.dev
- Laravel Documentation — https://laravel.com/docs
- Android Keystore System — https://developer.android.com/training/articles/keystore
- SQLCipher Documentation — https://www.zetetic.net/sqlcipher/

---

## Section 2: Project Organization

### 2.1. External Structure

| Stakeholder | Role in Project |
|---|---|
| **Arcenas Development Corporation** | Client / End-User Organization — HR Officers, Site Engineers, Executives, and Site Foremen will use the system. |
| **Eric Bulala** | Technical Adviser — Provides technical guidance on architecture and implementation; receives milestone completion reports. |
| **Engr. Clark Kevin V. Villamor** | Subject Adviser / Head, College of Computer Studies — Reviews and approves project scope, title, and documentation. |
| **Capstone Academic Institution** | Defines submission deadlines, defense evaluation criteria, and grading standards. |

### 2.2. Internal Structure

| Member | Role |
|---|---|
| Jay Mark A. Reños | Project Manager |
| Liza Mae C. Sugala | Systems Analyst |
| Rusel R. Portes | Lead Developer / Programmer |
| Efren S. Cabudbud Jr. | Database & QA Lead |
| CarlVey Sente | UI/UX Designer |
| Rayla G. Lanaza | Documentation Lead |

### 2.3. Roles and Responsibilities

| Role | Member | Responsibilities |
|---|---|---|
| Project Manager | Jay Mark A. Reños | Overall project coordination, timeline management, milestone tracking, stakeholder communication, risk management, resource allocation, meeting facilitation. |
| Systems Analyst | Liza Mae C. Sugala | Requirements gathering, use case specifications (UC-01 to UC-10), SRS drafting, stakeholder interviews, functional requirement validation. |
| Lead Developer / Programmer | Rusel R. Portes | Laravel backend API, React.js web frontend, React Native mobile app, cryptographic engine (Monotonic Clock, HMAC, TEE Signing), background sync engine. |
| Database & QA Lead | Efren S. Cabudbud Jr. | MySQL database schema, ERD, data dictionary, SQLCipher implementation, test case execution, defect tracking, STD preparation. |
| UI/UX Designer | CarlVey Sente | User interface design, user experience planning, wireframe and prototype development (PR-01 to PR-10), design consistency across web and mobile, usability improvement and user feedback integration. |
| Documentation Lead | Rayla G. Lanaza | SPMP/SRS/SDD/STD preparation, technical documentation, user manual preparation, documentation maintenance, all document formatting and packaging, presentation preparation. |

---

## Section 3: Managerial Process Plans

### 3.1. Start-up Plan

#### 3.1.1. Estimation Plan

| Component | Estimated Complexity | Primary Developer |
|---|---|---|
| Central Web HRIS (Laravel API + React.js) | High — 15 modules: RBAC, Payroll Engine, Leave Management, Crew Assignment, Analytics | Rusel R. Portes |
| Mobile Attendance App (React Native + SQLite/SQLCipher) | High — Offline digital checklist, local encrypted storage, background sync | Rusel R. Portes |
| Cryptographic Validation Engine (Monotonic Clock + HMAC + TEE) | Very High — Hardware-level cryptographic integration | Rusel R. Portes |
| Database Schema & ERD (MySQL, 13 tables) | Medium | Efren S. Cabudbud Jr. |
| UI/UX Prototypes (10 screen designs, PR-01 to PR-10) | Medium | CarlVey Sente |
| Full Documentation Package (SPMP, SRS, SDD, STD) | Medium | Rayla G. Lanaza + All Members |

#### 3.1.2. Staffing Plan

| Phase | Jay Mark (PM) | Liza Mae (Analyst) | Rusel (Dev) | Efren (DB/QA) | CarlVey (UI/UX) | Rayla (Docs) |
|---|---|---|---|---|---|---|
| Requirements Gathering | Lead | Lead | Support | Support | Support | Support |
| System Design | Oversight | Lead | Lead | Lead | Lead | Lead |
| Web Development | Oversight | Review | Lead | Support | Support | Support |
| Mobile Development | Oversight | Review | Lead | Support | Support | Support |
| Crypto Integration | Oversight | Review | Lead | Support | — | — |
| Testing & QA | Oversight | Review | Support | Lead | — | — |
| Documentation Finalization | Review | Support | Support | Support | Support | Lead |
| Defense Preparation | Lead | Support | Support | Support | Support | Lead |

#### 3.1.3. Resource Acquisition Plan

| Resource | Type | Purpose |
|---|---|---|
| Developer Workstations (6x) | Hardware | Individual development and documentation machines. |
| Android Test Smartphone(s) | Hardware | Real-device testing for React Native, SQLCipher, TEE Keystore signing, and offline attendance capture. |
| Local Development Server | Software/Hardware | Running Laravel API locally via Docker or XAMPP. |
| Node.js + npm | Software | React.js and React Native build environment. |
| PHP 8.x + Composer | Software | Laravel backend environment. |
| MySQL 8.0 | Software | Central HRIS relational database. |
| Git + GitHub/GitLab | Software | Version control and team collaboration. |
| VS Code | Software | Primary code editor. |
| Postman | Software | API endpoint testing. |
| Figma / draw.io | Software | UI/UX wireframing and diagram creation. |

#### 3.1.4. Project Staffing Plan

| Required Skill | Assigned Member |
|---|---|
| PHP / Laravel API Development | Rusel R. Portes |
| React.js (Web Frontend) | Rusel R. Portes |
| React Native (Mobile Development) | Rusel R. Portes |
| Cryptographic Engineering (HMAC, ECDSA, Monotonic Clock) | Rusel R. Portes |
| MySQL Database Design & Management | Efren S. Cabudbud Jr. |
| Software Quality Assurance & Test Case Execution | Efren S. Cabudbud Jr. |
| Requirements Analysis & Use Case Modeling | Liza Mae C. Sugala |
| UI/UX Design & Prototyping | CarlVey Sente |
| Technical Documentation & Report Writing | Rayla G. Lanaza |
| Project Coordination & Risk Management | Jay Mark A. Reños |

### 3.2. Work Plan

#### 3.2.1. Work Activities (Work Breakdown Structure)

**1.0 Project Management**
1.1 Project kickoff meeting
1.2 Milestone planning and Gantt chart creation
1.3 Weekly progress reporting
1.4 Risk identification and mitigation

**2.0 Requirements Engineering**
2.1 Stakeholder interviews with Arcenas Development Corp
2.2 Functional requirements identification (FR-01 to FR-10)
2.3 Use Case Specification writing (UC-01 to UC-10)
2.4 SRS document drafting and review

**3.0 System Design**
3.1 High-level architecture design (Web + Mobile + Crypto layers)
3.2 Database ERD design (13 tables)
3.3 Complete data dictionary
3.4 UI/UX wireframing and prototyping (PR-01 to PR-10)
3.5 Sequence diagrams, state transition diagrams, and process flowcharts
3.6 SDD document drafting and review

**4.0 Web Application Development**
4.1 MySQL database schema implementation
4.2 Laravel API — RBAC and authentication module (UC-01)
4.3 Laravel API — Worker Registry module (UC-02)
4.4 Laravel API — Crew Assignment module (UC-03)
4.5 Laravel API — Payroll Computation Engine (UC-08, Philippine Labor Code)
4.6 Laravel API — Leave and Overtime workflow (UC-10)
4.7 React.js — Web admin portal frontend for all modules
4.8 React.js — Executive analytics dashboard (UC-09)

**5.0 Mobile Application Development**
5.1 React Native — Project setup and navigation
5.2 React Native — Offline digital attendance checklist (UC-04)
5.3 React Native — Local SQLite/SQLCipher storage (supports UC-04)
5.4 React Native — Background sync engine (supports UC-04)
5.5 React Native — Late Foreman Override workflow (UC-05)

**6.0 Cryptographic Engine Development**
6.1 Monotonic hardware clock capture module (supports UC-04)
6.2 HMAC-SHA256 hash chaining ledger (supports UC-04)
6.3 TEE / Secure Enclave ECDSA payload signing (supports UC-04)
6.4 Server-side signature verification and chain integrity check (supports UC-04)

**7.0 Integration & Testing**
7.1 Web and Mobile API integration testing
7.2 Execution of STD test cases (TC-01 to TC-06)
7.3 Security tests: clock rollback, database tampering, MitM signature attack
7.4 Foreman edge case scenario tests
7.5 UAT with Arcenas Development Corp stakeholders

**8.0 Documentation Finalization**
8.1 SPMP, SRS, SDD, STD final review
8.2 Full document packaging for submission

**9.0 Capstone Defense**
9.1 Defense presentation preparation
9.2 System live demonstration setup
9.3 Q&A preparation
9.4 Capstone defense execution

> **Note:** The `UC-xx`/`FR-xx`/`PR-xx` IDs above are cross-referenced to SRS §3.2.1/§3.2.2, which previously had no formal ID scheme (use cases were identified only by figure number, e.g. "Fig. 5.0") and only 9 use cases — 6 short of the "UC-01 to UC-15" this WBS originally claimed. SRS has since been given explicit UC-01 to UC-10 / PR-01 to PR-10 IDs, including a new UC-10 (Leave & Overtime Filing and Approval) that covers work item 4.6 below, which previously had no matching SRS use case at all. Items 5.3, 5.4, and 6.1-6.4 are internal/technical tasks with no standalone SRS use case of their own — they're tagged "supports UC-04" (Mobile Digital Attendance Checklist) since that's the user-facing use case they implement underneath. Note also that this WBS's 15-item estimate for `FR-xx`/`UC-xx` was aspirational; the SRS itself only defines 10 of each.

#### 3.2.2. Schedule Allocation

| Phase | Start | End | Milestone Deliverable |
|---|---|---|---|
| Requirements Gathering | Week 1 | Week 3 | Approved SRS |
| System Design | Week 4 | Week 6 | Approved SDD + Prototypes |
| Web Portal Development | Week 7 | Week 11 | Functional Web HRIS |
| Mobile App Development | Week 10 | Week 13 | Functional Mobile App (Android APK) |
| Crypto Engine Integration | Week 12 | Week 14 | All 4 Crypto Layers Functional |
| Integration & Testing | Week 14 | Week 16 | All Test Cases Passed |
| Documentation Finalization | Week 15 | Week 17 | All 4 Documents Submitted |
| Capstone Defense | Week 18 | Week 18 | Defense Completed |

#### 3.2.3. Resource Allocation

| Resource | Allocated To | Phase |
|---|---|---|
| Android Smartphone (Test Device) | Rusel R. Portes / Efren S. Cabudbud Jr. | Mobile Dev + Testing |
| Local Laravel Dev Server (Docker/XAMPP) | Rusel R. Portes | Web + API Development |
| GitHub Repository | All Team Members | All Phases |
| Figma Account | CarlVey Sente | Design Phase |
| Postman | Rusel R. Portes + Efren S. Cabudbud Jr. | Integration & Testing |

#### 3.2.4. Budget Allocation

| Item | Estimated Cost | Notes |
|---|---|---|
| Android Test Device | ₱0 (team-owned) | Existing personal devices used for testing. |
| Domain / Staging Server | ₱0 (local staging) | XAMPP / Docker local environment for capstone defense. |
| Software Licenses | ₱0 | All tools are open-source or free tier. |
| Documentation Printing | ₱500 – ₱1,500 | Physical document printing for submission and defense. |
| Miscellaneous (transportation, meetings) | ₱500 – ₱1,000 | Team coordination expenses. |
| **Total Estimated Budget** | **₱1,000 – ₱2,500** | |

### 3.3. Control Plan

#### 3.3.1. Requirements Control Plan

- Any change to FR-01 to FR-10 or UC-01 to UC-10 must be reviewed in a team meeting.
- Approved changes are documented in the SRS with a version increment.
- Changes affecting the mobile offline behavior or cryptographic engine require Lead Developer review and adviser notification.

#### 3.3.2. Schedule Control Plan

- Weekly Friday progress check-ins to track milestone completion.
- Any delay of more than 5 days triggers an escalation meeting with the Project Manager.
- Critical path items (Cryptographic Engine, Payroll Engine) are monitored most closely.

#### 3.3.3. Budget Control Plan

- All project-related expenses must be approved by the Project Manager.
- Receipts for all purchases are tracked and documented.

#### 3.3.4. Quality Control Plan

- All code must be reviewed by at least one other team member before merging to the main branch.
- Unit tests must cover all critical modules: Payroll Engine, HMAC Hash Chain, Monotonic Clock, and TEE Signing.
- All UI prototypes must be reviewed by the Systems Analyst against use case specifications.
- All STD test cases (TC-01 to TC-06) must pass before documentation finalization.

#### 3.3.5. Reporting Plan

- **Daily:** Informal status updates via group chat (Messenger / Discord).
- **Weekly:** Formal Friday stand-up meeting (30–60 minutes) with progress report to Project Manager.
- **Per Milestone:** Written milestone completion report submitted to the Technical Adviser (Eric Bulala) upon each phase completion, copied to the Subject Adviser.

#### 3.3.6. Metrics Collection Plan

| Metric | Target | Collector |
|---|---|---|
| Unit Test Code Coverage | ≥ 80% for core modules | Efren S. Cabudbud Jr. |
| Defect Density | < 5 critical bugs at defense | Efren S. Cabudbud Jr. |
| Schedule Variance | ≤ 1 week deviation from Gantt | Jay Mark A. Reños |
| Attendance Sync Latency | < 5 seconds upon reconnection | Rusel R. Portes |
| Clock Tampering Detection Rate | 100% of simulated attacks detected | Efren S. Cabudbud Jr. |

#### 3.3.7. Risk Management Plan

| Risk | Likelihood | Impact | Mitigation Strategy |
|---|---|---|---|
| Team member unavailability (illness / emergency) | Medium | High | Cross-train on adjacent tasks; shared Git repo so any member can continue. |
| Android TEE API incompatibility on test device | Low | High | Test early on actual device; fallback to software key storage during dev with TEE integration last. |
| Mobile app sync failure on unstable network | Medium | Medium | Implement retry queuing with exponential backoff; test on simulated poor-network environment. |
| SQLite database corruption on mobile device | Low | High | Daily local database backup; HMAC chain integrity check detects corruption on sync. |
| Academic deadline changes from institution | Low | Medium | Monitor adviser announcements; maintain a 1-week buffer before all submission deadlines. |
| Payroll computation errors (Labor Code compliance) | Low | High | Verify payroll engine against multiple manual calculations; have HR stakeholder review outputs. |

#### 3.3.8. Project Closeout Plan

- All source code committed and tagged to final release version in the Git repository.
- All four documentation packages (SPMP, SRS, SDD, STD) printed and submitted.
- System access credentials handed over to Arcenas Development Corporation representative.
- Final project post-mortem meeting conducted to document lessons learned.
- GitHub repository archived and shared with the academic institution.

---

## Section 4: Technical Process Plans

### 4.1. Process Model

The project follows an **Agile-Waterfall Hybrid Development Model**:

- Waterfall is applied to documentation phases (SRS → SDD → SPMP → STD) ensuring sequential approval before development begins.
- Agile (Sprint-based) is applied to development phases — Web, Mobile, and Crypto modules are developed in 2-week sprints with iterative review cycles.

### 4.2. Methods, Tools, and Techniques

| Category | Tool / Technology |
|---|---|
| Backend Framework | Laravel (PHP 8.x) |
| Web Frontend | React.js with TailwindCSS |
| Mobile Framework | React Native (Android primary, iOS secondary) |
| Local Mobile Database | SQLite via SQLCipher (AES-256 encryption) |
| Central Database | MySQL 8.0 |
| Cryptography | HMAC-SHA256, ECDSA P-256, Hardware Monotonic Clock |
| Hardware Security | Android Keystore TEE / iOS Secure Enclave |
| Version Control | Git (GitHub or GitLab) |
| API Testing | Postman |
| IDE | Visual Studio Code |
| Diagramming | draw.io, Figma, Mermaid |
| Project Management | Group Chat (Messenger/Discord), Gantt Chart |

### 4.3. Infrastructure Plan

| Environment | Stack | Purpose |
|---|---|---|
| Local Development | XAMPP / Docker (PHP + MySQL) | Individual developer machines for building and testing. |
| Mobile Test Environment | Physical Android Device | Testing offline attendance logging, SQLCipher encryption, TEE signing, and background sync. |
| Staging Server | Local Network Server | Full-stack integration testing simulating production environment. |
| Production (Future) | Cloud Hosting (TBD post-capstone) | Actual deployment to Arcenas Development Corporation after defense. |

### 4.4. Product Acceptance Plan

The system is considered accepted when:

- All 10 functional requirements (FR-01 to FR-10) are fully implemented and verified.
- All 6 STD test cases (TC-01 to TC-06) pass, including clock rollback attack, database tampering, and TEE signature validation tests.
- The mobile offline attendance checklist successfully records and syncs attendance without data loss across at least 3 consecutive offline-then-online simulation cycles.
- The payroll engine produces accurate computations verified against manual calculations for OT, Night Differential, Rest Day, and Holiday scenarios.
- UAT sign-off is obtained from the designated Arcenas Development Corporation stakeholder representative.

---

## Section 5: Supporting Process Plans

### 5.1. Verification and Validation Plan

| Test Type | Coverage |
|---|---|
| Unit Testing | Payroll Engine, HMAC Hash Chain computation, Monotonic Clock delta math, RBAC role enforcement. |
| Integration Testing | Web Portal ↔ Laravel API ↔ MySQL; Mobile App ↔ Laravel API sync; TEE Signing ↔ Server verification. |
| Security Testing | TC-01 (Clock Rollback Attack), TC-02 (Direct DB Tampering), TC-03 (Man-in-the-Middle Signature Forgery). |
| Edge Case Testing | TC-04 (Late Foreman Override), TC-05 (Absent Foreman Crew Re-Assignment), TC-06 (Holiday Payroll Computation). |
| User Acceptance Testing | Stakeholder walkthrough of primary workflows: attendance logging, payroll processing, crew re-assignment, analytics viewing. |

### 5.2. Documentation Plan

| Document | Owner | Submission Timing |
|---|---|---|
| Software Project Management Plan (SPMP) | Rayla G. Lanaza | Before Development Phase |
| Software Requirements Specification (SRS) | Liza Mae C. Sugala | After Requirements Phase |
| Software Design Description (SDD) | Efren S. Cabudbud Jr. + Rusel R. Portes | After Design Phase |
| Software Test Document (STD) | Efren S. Cabudbud Jr. | After Testing Phase |
| User Manuals (Web Portal + Mobile App) | Rayla G. Lanaza | Before Defense |

### 5.3. Quality Assurance Plan

- Source code follows PSR-12 coding standards for PHP (Laravel) and Airbnb Style Guide for JavaScript (React.js / React Native).
- All API endpoints documented via Postman collections before integration testing.
- Security audit checklist executed against all 4 cryptographic layers before acceptance.
- All documents undergo peer review (minimum 2 team member review) before final submission.

### 5.4. Problem Resolution Plan

| Severity | Definition | Resolution Timeline | Escalation |
|---|---|---|---|
| Critical | System crash, data loss, security vulnerability (e.g., hash chain broken, sync failure) | Within 24 hours | Immediate escalation to Project Manager and Lead Developer. |
| Major | Feature not working as specified (e.g., payroll error, attendance not saving offline) | Within 3 days | Report to Project Manager; re-prioritize sprint. |
| Minor | UI/UX issue, cosmetic bug, incorrect label or rounding | Within 1 week | Logged in defect tracker; addressed in next sprint. |

---

*[END OF DOCUMENT]*
