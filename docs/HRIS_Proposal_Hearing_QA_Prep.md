# Proposal Hearing — Anticipated Questions

**HRIS for Arcenas Development Corporation**
Companion to `docs/HRIS_Proposal_Hearing.pptx` (43 slides).

Questions are grouped by the proponent most likely to be asked, based on the slides they
present and their role in SPMP §2.3. Each entry gives the question, the line to take, and
where the supporting detail lives.

> **Rule for the whole team:** if a question lands outside your slides, hand it to the owner
> by name rather than guessing. A clean hand-off reads as a team that knows its own scope.
> Section 4 below lists the things you must *not* overstate.

---

## 1. Jay Mark A. Reños — Project Manager
*Slides 1–2, 17, 39–40, 42–43 — scope, resources, budget, schedule, closing*

**Q1. Is Arcenas Development Corporation an actual committed client? Do you have a written agreement?**
A discovery interview instrument ("Workforce & Records", dated 08 September 2026) was prepared
and issued to the company, and a designated stakeholder is committed to UAT sign-off per SPMP
§4.4. Be straight that the completed form has not been returned yet, and say what you will do
about it — see §4.1 below. Do not imply requirements are already client-validated.

**Q2. Six members, but SPMP §3.1.1 assigns the web portal, the mobile app *and* the crypto engine all to one developer. Is 18 weeks realistic?**
Acknowledge it directly — it is the project's single biggest schedule risk. Mitigations are in
SPMP §3.3.7: cross-training on adjacent tasks, a shared repository so any member can continue,
a one-week buffer before every submission deadline, and phase overlap (mobile starts week 10
while web is still running). Say the critical path is the crypto engine and payroll engine, and
those are monitored most closely.

**Q3. Your budget is ₱1,000–₱2,500. What happens in production — who pays for hosting?**
The capstone runs entirely on local/staging infrastructure (XAMPP or Docker), which is why the
figure is printing and transport only. Production cloud hosting is explicitly post-capstone and
to be selected with the client (SPMP §4.3). No paid third-party APIs are required at any stage
because the cryptography runs on hardware the phones already have.

**Q4. What is your biggest technical risk and what is the contingency?**
TEE/Keystore API incompatibility on the test device. Contingency per SPMP §3.3.7: test on a real
device early, fall back to software key storage during development, and integrate TEE last so a
failure there does not block everything upstream.

**Q5. If the crypto engine slips past week 14, what breaks?**
Integration and testing (weeks 14–16) absorbs roughly one week of slip; documentation
finalisation (weeks 15–17) is the second buffer. Any delay beyond five days triggers an
escalation meeting per SPMP §3.3.2.

**Q6. Why build this instead of buying an off-the-shelf HRIS or a biometric time clock?**
Every commercial option assumes two things Arcenas does not have at its remote sites: a network
connection at the moment of capture, and a fixed guard post or terminal to install a device on.
A biometric clock also cannot answer the tampering problem, because it still trusts its own clock.

**Q7. What does success look like — how will you know it worked?**
Quote SPMP §4.4: all ten functional requirements implemented, all six STD test cases passing,
three consecutive offline-then-online cycles with no data loss, payroll matching manual
computation for every premium scenario, and stakeholder UAT sign-off.

---

## 2. Liza Mae C. Sugala — Systems Analyst
*Slides 3–10, 12–13 — problem, objectives, solution overview*

**Q1. How did you gather these requirements? Who did you talk to, and when?**
Describe the instrument honestly: a structured discovery interview covering roles/kinds of
worker (A-01 to A-09) and data to collect (B-01 to B-11), issued 08 September 2026. Say plainly
that requirements to date are drawn from the SRS/SPMP scoping work and the company's stated
process, and that the returned instrument will confirm or correct them.

**Q2. What is your evidence that timestamp manipulation actually happens at Arcenas?**
Do not claim a documented incident you cannot produce. The correct framing is structural: on the
current process, nothing distinguishes a sheet filled in at 7:00 AM from one filled in at noon,
so the record is unverifiable *by construction*. The system removes the possibility rather than
responding to a specific proven case.

**Q3. You list seven roles but only five can log in. Why can't a worker see their own attendance?**
Worker and Operator are record-only classifications in the client's current structure — they
appear on rosters and payroll but have no account. Note that question A-09 of the discovery
interview asks the company directly whether that is deliberate policy or just the absence of a
system. Worker self-service is out of scope for this capstone.

**Q4. How many sites actually have no signal?**
Give the honest count if you have it and say "several of the eleven active sites" only if that is
what the client told you. If you do not have the number, say so and say it is being confirmed —
an invented figure is the easiest thing for a panel to catch.

**Q5. What is explicitly *out* of scope?**
Recruitment and applicant tracking, training records, procurement and equipment, government
e-filing submission, and worker self-service. The system covers UC-01 to UC-10 only.

**Q6. Why exactly ten use cases? Why was UC-10 added late?**
The leave and overtime module already existed in the SDD (§2.1.7, §3.1.11–12, §4.6) and in the
SPMP WBS (4.6) but had no SRS use case of its own; UC-10 was added to close that gap. An earlier
SPMP draft assumed fifteen UCs before the IDs existed — that estimate was aspirational and has
been reconciled to ten.

**Q7. Your problem statement says payroll errors happen. In which direction, and how much?**
Errors run both ways — underpaid workers and overpaid hours — because premium rates are computed
by hand per worker per cutoff. Do not quote a peso figure unless the client gave you one.

---

## 3. Efren S. Cabudbud Jr. — Database & QA Lead
*Slides 11, 31 — modules and data structure, audit trail and compliance*

**Q1. Why thirteen tables? Walk us through the core relationships.**
Be ready to trace one path out loud: `Role → Employee → Crew Assignment → Crew → Site`, then
`Attendance → Attendance Sync Queue → Crypto Signature Ledger`, then `Payroll → Payroll Detail`,
with `Audit Log` cross-cutting. Note RBAC runs off `Employee.role_id` with no separate users
table — that is a deliberate design decision, not an omission.

**Q2. Where is attendance stored on the phone, and how is it protected at rest?**
The design is SQLite encrypted with SQLCipher (AES-256). **Be accurate about status:** local
SQLite storage is working; the SQLCipher encryption layer is scheduled for Phase 5 and is not
yet in place. Say "designed and scheduled", not "implemented".

**Q3. Your audit log lives in the same MySQL database. What stops a database administrator from editing it?**
The strongest honest answer: the audit log is append-only at the application layer, but a
database superuser is outside what any in-database control can stop. That is precisely why
integrity does not rest on the audit log alone — the HMAC chain and the signatures in the Crypto
Signature Ledger are verifiable independently, so an edited row stops re-computing. Claiming the
DB itself is tamper-proof will not survive follow-up.

**Q4. How will you actually prove tampering is detected, rather than asserting it?**
Three adversarial test cases, executed not just described: TC-01 clock rollback, TC-02 direct
edit of the local database, TC-03 man-in-the-middle signature forgery. Target is 100% detection
of simulated attacks (SPMP §3.3.6).

**Q5. What is your test coverage target, and how do you measure it?**
≥80% unit-test coverage on core modules — payroll engine, HMAC chain, monotonic clock delta
math, RBAC enforcement — with defect density under five critical bugs at defense.

**Q6. What happens to a worker's records when they leave the company?**
Be honest that retention policy is one of the open questions put to the client (interview B-11,
including the legal minimum retention period), and the schema will follow their answer.

**Q7. Can you run the test cases today?**
No — four of the six depend on modules in Phases 5, 7 and 8 that are not built yet. The STD is a
test *plan*; Actual Result and Pass/Fail columns are intentionally blank until execution.

---

## 4. Rayla G. Lanaza — Documentation Lead
*Slides 14–15, 41 — development methodology, milestone deliverables*

**Q1. Why an Agile-Waterfall hybrid rather than committing to one?**
The two halves of the project have different needs. Documentation must be approved in sequence
(SRS → SDD → SPMP → STD) because each one is a graded deliverable that the next depends on.
Development of the crypto and payroll engines genuinely needs iteration, so those run in two-week
sprints. Splitting by phase type rather than forcing one model on both is the honest choice.

**Q2. If a requirement changes mid-sprint, how does it get back into an already-approved SRS?**
SPMP §3.3.1: any change to FR-01–FR-10 or UC-01–UC-10 is reviewed in a team meeting, then
documented in the SRS with a version increment. Changes touching offline behaviour or the crypto
engine additionally require Lead Developer review and adviser notification.

**Q3. Which documents are finished right now?**
SPMP, SRS, SDD and STD are all drafted. Be precise about the known gaps rather than letting the
panel find them: SDD Section 5 (Human Interface Design) is still an empty heading in the Word
build, and UC-10/PR-10 carry "to be attached" placeholders because those two diagrams do not
exist yet.

**Q4. Your SPMP.docx and SPMP.md do not match — one schedules by calendar date, the other by week number. Which one governs?**
This is a real, known divergence and you should own it. The docx is the submission copy; the
markdown is the working plan. They also differ in WBS structure (labour-hour table vs nine
UC-tagged groups). Say reconciliation is an open item, and that the team deliberately did not
overwrite either from the other without deciding which is authoritative.

**Q5. How do four documents stay in sync as the code changes?**
The SDD data dictionary is kept current with the Laravel migrations, and the SPMP WBS is
cross-referenced to SRS §3.2.1/§3.2.2 by UC/FR/PR ID so a change in one surfaces in the other.
Peer review of at least two members before any document is finalised (SPMP §5.3).

**Q6. What will the client actually receive as documentation?**
User manuals for both the web portal and the mobile app before defense (SPMP §5.2), plus the full
four-document package and handover of system credentials at closeout.

---

## 5. Rusel R. Portes — Lead Developer
*Slides 16, 18–30 — technology stack and all ten core features*

**Q1. What stops a foreman from sitting at home and marking his whole crew present?**
This is the hardest question in the defense and it will be asked. Answer it head-on: the system
proves *when* a record was made and *which bound device* made it. It does not prove the foreman
was physically at the site. Location capture is deliberately not in scope — GPS is spoofable on a
rooted device, so it would add the appearance of proof without the substance. What the system
does give management is an attendance record that cannot be back-dated or quietly edited later,
which is the specific failure of the paper process. Volunteer geofencing as a possible future
enhancement rather than defending a claim you cannot support.

**Q2. The monotonic clock resets on reboot. How do you detect a rollback that spans a restart?**
Each record stores the monotonic reading together with a boot session identifier, and the HMAC
chain continues across reboots. A reboot therefore shows as a legitimate discontinuity in the
monotonic counter while the hash chain stays intact; a clock rollback shows as wall-clock time
moving backwards relative to a chain that only moves forwards. The server checks both.

**Q3. On a rooted phone, can't an attacker call your signing function with fabricated data?**
Yes, in principle — and say so. The TEE protects the *key* from extraction, not the *input* from
being fabricated by software running on a compromised device. What that attack still cannot do is
rewrite records already chained and synced, forge a batch from a device that is not bound, or
produce a batch whose monotonic and wall-clock readings agree when they should not. The defence
is layered precisely because no single layer is sufficient.

**Q4. Why ECDSA P-256 rather than RSA?**
P-256 is what Android Keystore and the Secure Enclave support in hardware, signatures are far
smaller (important when syncing over a weak connection), and signing is faster on mobile
silicon. RSA keys of comparable strength are larger and slower with no benefit here.

**Q5. Where does the HMAC key live? The device has to hold it to compute the chain.**
Be precise: the chain gives tamper-*evidence*, not secrecy. Its security comes from the chain
being re-computed server-side and from each batch being signed by the hardware key, which cannot
be extracted. Do not claim the HMAC key alone protects anything on a compromised device.

**Q6. A foreman loses his phone, or is issued a new one. What happens?**
New device, new key, new binding, and the old public key is revoked. Anything still queued on the
lost device and never synced is genuinely lost — that is a real gap, and the mitigation is the
retroactive crew recovery sign-off workflow (Core Feature 07), which exists exactly for days that
were never captured.

**Q7. Overtime worked on a holiday — do the multipliers stack, and in what order?**
Know your answer before the hearing. The engine applies Labor Code premiums in the order RA 442
specifies, and the payroll detail line shows the derivation per worker so the computation can be
checked by hand. TC-06 verifies holiday computation against manual calculation.

**Q8. What if two foremen log the same worker on the same day?**
The crew assignment determines who holds attendance authority for that crew on that date, so a
duplicate surfaces as a conflict at verification rather than being silently accepted. Acting
foreman re-assignment transfers that authority explicitly and is logged.

**Q9. CLAUDE.md says Laravel 8+, but the repository is on Laravel 13 and PHP 8.4. Which is it?**
"Laravel 8+" is a floor, not a pin; the build targets Laravel 13 on PHP 8.4. Say it plainly so it
does not read as drift from the fixed stack.

**Q10. How do you guarantee sync completes in under five seconds?**
It is a target, not a guarantee, and it is measured on reconnection for a normal batch. Retry
with exponential backoff covers the cases where it does not hold. Do not promise it as an
absolute.

**Q11. Why React Native rather than a native Android app?**
One codebase across Android (primary) and iOS (secondary), with native modules only where the
hardware demands it — Keystore/Secure Enclave signing and the monotonic clock. The parts that
must be native, are.

---

## 6. CarlVey Sente — UI/UX Designer
*Slides 32–38 — the thirteen prototype screens*

**Q1. Have you tested these screens with an actual site foreman?**
Answer honestly. If formal usability testing has not happened yet, say so and say when it will —
usability validation is planned alongside UAT with the client's designated users. Claiming
validation you have not done is the fastest way to lose a design defense.

**Q2. Why three buttons per worker instead of a time-in / time-out clock?**
Because the foreman is recording *other people*, not clocking himself. Present, Late and Absent
is the complete decision he actually makes during a roll call, it needs one tap per worker rather
than two events per worker, and it produces no free-text or time entry to get wrong.

**Q3. How does this work in direct sunlight, or with gloves on?**
Large touch targets, high-contrast text on solid fills rather than thin type on photos, and no
small controls in the primary flow. The status bar states connection and pending count in words,
not icons alone, so it survives glare.

**Q4. Your users may not be confident with smartphones. How did you design for that?**
No typing anywhere in the roll-call path, no jargon on screen — device binding says "creating
this phone's safety lock", never "generating a cryptographic key" — and every screen states in
plain language what happened and what is still pending ("Saves to this phone. Nothing is lost if
you close the app.").

**Q5. The sites are in Cebu. Why is the interface English-only?**
A fair hit. Localisation to Cebuano or Tagalog is not in the current scope. The mitigation in the
design is to keep the field vocabulary to a handful of words and rely on colour and position, but
say openly that localisation would be the first usability enhancement after the capstone.

**Q6. Your mobile artboards are 390 px wide. What happens on a smaller or older phone?**
390 px is the design reference width; the layout is a single scrolling column with no fixed
multi-column regions, so it reflows on narrower screens. The minimum target device is Android 10
with 3 GB RAM per SRS §3.1.1.

**Q7. The web and mobile screens look like one product. How did you enforce that?**
A single shared design system — one set of colour, type and spacing tokens compiled from
`HRIS Design System` and consumed by both the web build and the prototypes — rather than styling
each screen independently.

**Q8. Are these real prototypes or pictures?**
They are working HTML prototypes and you can click through them. Offer to demonstrate one live if
the panel wants it — that lands better than any slide.

---

## 7. Answer honestly — do not overstate these

The panel will find these faster than you think. Each one is defensible if you own it first, and
damaging if you are caught claiming otherwise.

| # | The temptation | The accurate statement |
|---|---|---|
| 1 | "Requirements are confirmed with the client." | The discovery interview was issued on 08 Sep 2026; the completed form has not been returned. Requirements are scoped, not yet client-validated. |
| 2 | "Local storage is encrypted with SQLCipher." | SQLite storage works; SQLCipher/AES-256 is designed and scheduled for Phase 5, not yet implemented. |
| 3 | "Offline capture is proven." | Verified by unit tests and code path review, not yet by an on-device airplane-mode run — no Android emulator has been available. |
| 4 | "The system proves the foreman was at the site." | It proves *when* and *which device*. Physical presence is not established; location capture is out of scope. |
| 5 | "The audit log cannot be altered." | It is append-only at the application layer. Integrity against a database-level actor rests on the hash chain and signature ledger, which verify independently. |
| 6 | "All documentation is final." | SDD §5 is an empty heading; UC-10/PR-10 figures are "to be attached"; SRS §3.5.2 and §3.5.3 have swapped content (Availability describes access control, Security describes usability). |
| 7 | "SPMP.md and SPMP.docx are the same plan." | They diverge in schedule format and WBS structure. Reconciliation is an open item. |
| 8 | "Sync always completes in under 5 seconds." | That is a measured target with retry and backoff behind it, not a guarantee. |

---

## 8. Quick reference — who owns what

| Presenter | Role | Slides | Time |
|---|---|---|---|
| Jay Mark A. Reños | Project Manager | 1–2, 17, 39–40, 42–43 | ~10 min |
| Liza Mae C. Sugala | Systems Analyst | 3–10, 12–13 | ~11.5 min |
| Efren S. Cabudbud Jr. | Database & QA Lead | 11, 31 | ~3 min |
| Rayla G. Lanaza | Documentation Lead | 14–15, 41 | ~4 min |
| Rusel R. Portes | Lead Developer / Programmer | 16, 18–30 | ~16.5 min |
| CarlVey Sente | UI/UX Designer | 32–38 | ~9 min |

**Total ≈ 54 minutes**, leaving roughly six minutes of the hour for questions.
