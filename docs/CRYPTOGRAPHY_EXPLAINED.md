# The Attendance Integrity Engine, Explained

**A plain-English guide to the cryptography in the HRIS project.**

This document explains *why* the system uses cryptography, *what* each piece
does, and *what it cannot do*. It assumes no prior security background. Every
technical term is defined in the [Glossary](#glossary) before it is used in
anger.

Audience: the capstone team, the panel, and anyone who has to defend or
maintain this code.

---

## 1. Why is there cryptography in an attendance app at all?

Because attendance records are money.

At Arcenas Development Corporation, a worker's pay is computed from the
attendance records a Site Foreman taps into a phone. The phone is often at a
remote site with **no internet connection**, so records sit on that phone —
sometimes for hours — before they reach the server.

That creates a window. During that window, the records are sitting in a
database file on a device that the person being paid (or the person recording
the pay) physically controls. Three specific things could happen:

| # | Attack | What the attacker does | Payroll effect |
|---|---|---|---|
| 1 | **Clock rollback** | Change the phone's clock back two hours, then tap "Present" | Worker is credited for arriving at 6 AM when they arrived at 8 AM |
| 2 | **Database tampering** | Open the app's local SQLite file and edit a row from `absent` to `present` | Absent worker gets paid a full day |
| 3 | **Forged submission** | Skip the app entirely; send fake records straight to the server's API | Any records at all, invented from nothing |

None of these require deep technical skill. Rolling back a phone clock takes
three taps in Settings. So the system's job is: **when a record arrives at the
server, prove it is the record the foreman actually tapped, at the time they
actually tapped it, on the device we actually issued.**

That proof is what the four layers below provide.

### The single most important caveat, stated up front

This system makes records **tamper-evident**, not **tamper-proof**.

- **Tamper-proof** would mean nobody can ever alter a record. That is
  impossible on a device an attacker physically holds.
- **Tamper-evident** means that *if* a record is altered, the server can
  detect it and refuse it.

And critically: **no amount of cryptography stops a foreman from marking an
absent worker "Present" in the first place.** If the foreman lies at the
moment of tapping, the system faithfully, cryptographically protects a lie.
That is a human-supervision problem (sometimes called "buddy punching"), not a
cryptographic one. What this engine guarantees is narrower and still valuable:
**the record cannot be changed after the fact, and cannot be fabricated by
anyone who is not holding an enrolled device.**

Be precise about this distinction when defending the project. Overclaiming
("our system prevents attendance fraud") invites a panelist to dismantle it in
one question. The accurate claim ("our system makes attendance records
tamper-evident and provably device-originated") holds up.

---

## 2. Glossary

Read this once; the rest of the document leans on it.

### Hashing

**Hash function** — A function that turns any input into a fixed-length
fingerprint. Same input always gives the same fingerprint; changing even one
character gives a completely different one. You cannot run it backwards to
recover the input.

> *Analogy:* blending a fruit into a smoothie. The same fruit always makes the
> same smoothie, but you cannot un-blend a smoothie back into fruit.

**SHA-256** — The specific hash function used here. Produces a 256-bit (64
hex-character) fingerprint. Industry standard, used everywhere from TLS to
Bitcoin.

```
SHA-256("absent")  → 5fdd5d2b...  (64 hex chars)
SHA-256("present") → 9f86d081...  (completely different)
```

### Keys and secrets

**Key** — A secret value that makes a cryptographic operation specific to you.
Without the right key, an attacker cannot produce the right output.

**Symmetric key** (also *shared secret*) — **One** key that both sides hold,
used both to produce and to check. Fast, simple. Weakness: because both sides
hold the same key, either side *could* produce a valid output — so it proves
"this wasn't altered", not "this came from you specifically".

> *Analogy:* a wax seal stamp that you and your business partner both own a
> copy of. A sealed letter proves it wasn't opened in transit, but not which
> of you sealed it.

**Asymmetric key pair** — **Two** mathematically linked keys:
- a **private key**, which never leaves its owner and is used to *sign*
- a **public key**, which is freely shared and is used to *verify*

Only the private key can create a signature; anyone with the public key can
check it. This *does* prove origin.

> *Analogy:* your handwritten signature. Only you can produce it, but anyone
> can compare it against a specimen on file.

### The two cryptographic operations used here

**HMAC** (*Hash-based Message Authentication Code*) — A hash **plus a
symmetric key**. Answers: *"has this data been altered by someone who didn't
have the key?"* Written `HMAC-SHA256` because it uses SHA-256 internally.

A plain hash isn't enough on its own: if an attacker edits a record, they can
just recompute the plain hash to match. With HMAC they cannot, because they'd
need the key.

**Digital signature** — A hash **plus a private key**. Answers the stronger
question: *"did this data come from the holder of a specific private key, and
is it unaltered?"*

**ECDSA** (*Elliptic Curve Digital Signature Algorithm*) — The signature
algorithm used here. "Elliptic curve" refers to the mathematics behind it; the
practical benefit is that it offers strong security with small keys (a 256-bit
ECDSA key is roughly as strong as a 3072-bit RSA key), which matters on
phones.

**P-256** (also written `prime256v1` in OpenSSL, or `secp256r1`) — The
specific elliptic curve used. A standardised, widely-supported choice; it is
what Android's hardware keystores support natively.

### Hardware security

**TEE** (*Trusted Execution Environment*) — **This is the key term to
understand.** A physically separate, isolated region of the phone's processor
that runs its own tiny operating system, walled off from Android itself.

Code inside the TEE can hold secrets that code *outside* it — including
Android, including a malicious app, including the device owner with root
access — **cannot read**. You can ask the TEE to *use* a key ("sign this for
me") but you cannot ask it to *hand over* the key. The key is
**non-exportable**.

> *Analogy:* a bank's safe-deposit vault inside a shopping mall. The mall is
> Android — busy, full of strangers, and you can walk anywhere. The vault is
> the TEE. You can hand the teller a document to be stamped with your seal,
> and get it back stamped, but you can never walk out holding the seal itself.
> Even if you own the mall.

Why this matters here: the attendance signing key lives in the TEE. So an
attacker who fully compromises the phone still **cannot extract the key and
sign fake records on their laptop**. They would have to use that specific
physical device.

**Android Keystore** — Android's API for creating and using keys, with the
option to have them stored in the TEE.

**Secure Enclave** — Apple's equivalent of the TEE. Named separately because
it is a different implementation; conceptually the same idea. (This project is
Android-primary, so the TEE is the path that matters; iOS is secondary.)

**StrongBox** — An even stronger tier than the standard TEE: a *dedicated
physical security chip*, separate from the main processor entirely. Android
reports a key's protection level as one of `SOFTWARE` (no hardware
protection — just a file), `TRUSTED_ENVIRONMENT` (TEE), or `STRONGBOX`
(dedicated chip). This project accepts the latter two as genuinely
hardware-backed.

### Time

**Wall clock** — The normal human-readable time the phone shows
(`time_in` in our records). **Freely settable by the user in Settings.**
Therefore untrusted.

**Monotonic clock** — A counter of *milliseconds since the device last
booted*. On Android this is `elapsedRealtime()`. It only ever counts
**upward**, at a constant rate, and — crucially — **there is no setting
anywhere in Android that lets a user change it.** It ignores clock changes,
timezones, and daylight saving entirely.

> *Analogy:* a stopwatch that started when you switched the phone on. You can
> lie about what time it is on the wall, but you cannot make the stopwatch run
> backwards.

**Boot ID** (`boot_id`) — A random identifier regenerated every time the phone
reboots. Needed because the monotonic counter resets to ~0 on reboot; without
knowing *which* boot session a reading belongs to, a legitimate restart would
look identical to tampering.

### Other terms

**Hash chain** — A sequence of records where each record's fingerprint
includes the previous record's fingerprint, linking them into a chain.
(Explained fully in [Layer 2](#layer-2--hmac-sha256-hash-chaining).)

**Canonical serialization** — Converting a record into text in one single,
rigidly-defined way, so that two different programs always produce **byte-for-byte
identical** output. (Explained in [Section 4](#4-the-canonical-payload-the-unsung-critical-piece).)

**MitM** (*Man-in-the-Middle*) — An attacker positioned between the app and
the server, able to read and modify traffic in transit.

**Trust anchor** — The one thing you must establish securely, and from which
all other trust derives. Here: device binding.

**DER** — A standard binary format for packaging a signature. Relevant only
because the signature is DER-wrapped and then base64-encoded for transport.

**Base64** — A way of writing raw binary data using only ordinary text
characters, so it can travel safely inside JSON. Not encryption; it hides
nothing.

**SQLCipher / AES-256** — SQLCipher transparently encrypts the entire local
SQLite database file using AES-256 (a standard encryption algorithm). Without
it, the database is a plain file any file browser can open and edit.

---

## 3. The four layers at a glance

No single mechanism solves this. Each layer closes a specific hole the others
leave open, and each is independently verified on the server.

```
  ON THE PHONE                                      ON THE SERVER
  ─────────────────────────────────────────         ──────────────────────────

  Layer 1  Monotonic clock capture
           elapsedRealtime + boot_id       ──────►  ClockIntegrityVerifier
           "when did this really happen?"            cross-check both clocks

  Layer 2  HMAC-SHA256 hash chaining
           each record links to the prior   ──────►  HashChainVerifier
           "was the local log edited?"                recompute every HMAC

  Layer 3  ECDSA P-256 signing in the TEE
           private key never leaves chip    ──────►  SignatureVerifier
           "did an enrolled device send this?"        verify vs public key

  Layer 4  ────────────────────────────────────────  Server-side verification
                                                     is the arbiter. The phone
                                                     can claim anything; only
                                                     the server decides.
```

| Layer | Stops | Cannot stop alone |
|---|---|---|
| 1. Monotonic clock | Clock rollback | Editing the stored record afterwards |
| 2. HMAC hash chain | Editing or reordering stored records | Forgery by anyone holding the shared key |
| 3. ECDSA in TEE | Off-device forgery; proves origin | A foreman lying at tap time |
| 4. Server verification | Trusting any device claim | — (it is the decision point) |

The pattern to notice: **each layer's weakness is covered by the next.**

---

## 4. The canonical payload: the unsung critical piece

Before the layers, one piece of plumbing that everything else depends on.

Both the phone (TypeScript) and the server (PHP) must compute hashes and
signatures over **exactly the same bytes**. If they disagree by even a single
space, every signature fails and the failure *looks like* a crypto bug when it
is really a formatting bug.

The obvious approach — JSON — does not work, because JS and PHP disagree on:
- **key ordering** (`{"a":1,"b":2}` vs `{"b":2,"a":1}`)
- **unicode escaping** (`é` vs `é`)
- **number rendering** — PHP renders `1e20` as `"1.0E+20"`, JS as
  `"100000000000000000000"`

So the project defines its own rigid format: a fixed list of fields, in a
fixed order, one `key=value` per line, prefixed with a version marker.

**Implementation:** [`mobile/src/crypto/payload.ts`](../mobile/src/crypto/payload.ts)
and [`backend/app/Services/Crypto/AttendancePayload.php`](../backend/app/Services/Crypto/AttendancePayload.php)
— deliberate mirrors of each other, pinned by a shared test vector on both
sides.

A canonical payload looks like this:

```
version=v1
employee_id=42
crew_id=1
date=2026-09-12
status=present
time_in=1757649600000
monotonic_timestamp=845123
boot_id=b3f1c2d4e5
device_id=dev-mg8x2k-a91f
prev_hash=9f86d081884c7d65...
```

Three defensive details worth understanding:

1. **Integers only.** A non-integer number throws an error rather than being
   rendered, precisely because of the float-formatting divergence above.
2. **Line breaks are rejected.** A newline inside a field value could forge a
   fake field boundary — e.g. a name of `"Smith\nstatus=present"` would inject
   a second `status` line. Rejecting them closes that injection.
3. **Versioned.** The format can only change by bumping `PAYLOAD_VERSION` and
   updating both sides and both test vectors together.

---

## 5. Layer 1 — Monotonic clock capture

**Attack it stops:** the clock rollback (STD **TC-01**).

### The problem

A foreman arrives at 8:00 AM but wants credit from 6:00 AM. They open
Settings, set the phone's clock back two hours, and tap "Present". The record
now genuinely says 6:00 AM. Nothing about the record itself looks wrong.

### The mechanism

Every record carries **two independent times**:

- `time_in` — the wall clock. **Untrusted** (the user can set it).
- `monotonic_timestamp` — `elapsedRealtime()`, milliseconds since boot.
  **Cannot be set by the user at all.**

Between any two consecutive records from the same boot session, *both clocks
should advance by the same amount.* If 40 minutes of stopwatch time passed,
then 40 minutes of wall time should have passed too.

So the server compares the two deltas:

```
wall clock advanced:      -2h 00m   ← went backwards!
monotonic advanced:       +0h 05m   ← only 5 minutes really passed
disagreement (drift):      2h 05m   ← far beyond tolerance → REJECTED
```

**Implementation:** [`ClockIntegrityVerifier.php`](../backend/app/Services/Crypto/ClockIntegrityVerifier.php)

### The details that make it actually work

- **Tolerance.** Honest drift exists — NTP corrections, timezone changes,
  rounding — but it is *seconds*. An attack is *minutes to hours*. The
  tolerance is **120 seconds** (`crypto.clock_skew_tolerance_seconds`),
  generous enough never to flag honest drift, tight enough to catch any useful
  attack.
- **Reboots.** `elapsedRealtime` resets to ~0 on reboot, so a naive "monotonic
  must always increase" rule would flag *every legitimate restart* as an
  attack. Records carry `boot_id`; when it changes, the server records a known
  discontinuity instead of crying tamper. Chain continuity across the reboot is
  still guaranteed by Layer 2's `prev_hash`, so a reboot cannot be used to
  smuggle in forged history.
- **Regression is fatal.** Within one boot session, a monotonic counter that
  went *backwards* is physically impossible — so that value was fabricated.
  Rejected outright (`monotonic_regressed`).
- **Absent workers.** An absent worker has no `time_in`, so there is no wall
  clock to cross-check. Monotonic ordering still applies.
- **Direction is distinguished.** A backwards jump
  (`wall_clock_rolled_back`) is the classic attack; a forwards jump
  (`wall_clock_jumped_forward`) is more likely misconfiguration. Both are
  rejected, but the audit trail doesn't conflate them.

### What it cannot do

The **first** record from a device has nothing to compare against — it
establishes the baseline (`reason: 'baseline'`). This is precisely why device
binding is the trust anchor.

---

## 6. Layer 2 — HMAC-SHA256 hash chaining

**Attack it stops:** editing the local database (STD **TC-02**).

### The problem

The local SQLite file sits on the device. An attacker with file access opens
it and changes one row's `status` from `absent` to `present`.

### The mechanism, built up in three steps

**Step 1 — hash each record.** Gives a fingerprint, so alteration is visible.
But an attacker can just recompute the hash after editing. Not enough.

**Step 2 — use HMAC instead of a plain hash.** Now the fingerprint requires a
**secret key** the attacker doesn't have. They can edit the row, but cannot
produce a matching `hmac_hash`. Better — but they could still *delete* a
record, or *reorder* them.

**Step 3 — chain the records.** Each record's payload *includes the previous
record's hash* (`prev_hash`). So a record's HMAC commits not just to its
contents but to **its position in the sequence**.

```
Record 1 ──► hmac_hash: aaa111
                 │
                 └──► Record 2 (prev_hash: aaa111) ──► hmac_hash: bbb222
                                                          │
                                                          └──► Record 3 (prev_hash: bbb222) ──► hmac_hash: ccc333
```

Now edit Record 2. Two things break at once:
1. Record 2's own `hmac_hash` no longer matches its contents.
2. Record 3's `prev_hash` still points at the *old* Record 2 hash — so the
   linkage from 2 to 3 is broken too.

**Tampering with one record invalidates that record and every record after
it.** You cannot quietly change history; you can only visibly destroy the
chain from that point on.

**Implementation:** [`mobile/src/crypto/hashChain.ts`](../mobile/src/crypto/hashChain.ts)
(builds the chain) and [`HashChainVerifier.php`](../backend/app/Services/Crypto/HashChainVerifier.php)
(recomputes and verifies it).

### Details that matter

- **Partial acceptance.** The verifier returns a *per-record* result rather
  than throwing, because TC-02 requires exactly this: record 1 accepted,
  records 2 and 3 rejected, and the response must say **where** the chain
  broke (`prev_hash_mismatch`, `hmac_mismatch`, `chain_broken_upstream`).
- **The app self-checks first.** `verifyLocalChain()` mirrors the server's
  logic so the app can surface a broken chain on the Sync Queue screen
  *before* transmitting — rather than the server rejecting a batch after the
  foreman has walked away.
- **Key encoding is a real footgun.** The server issues the per-device HMAC
  secret as **base64 text**, but both sides must HMAC using the **decoded raw
  bytes**. Using the base64 *string* as the key produces a perfectly
  valid-looking HMAC that simply never matches. Hence
  `hmacKeyFromBase64()` exists and is documented loudly in the source.

### What it cannot do — and why Layer 3 exists

**The HMAC key is shared with the server.** It has to be, or the server
couldn't recompute the HMAC to check it.

That means this layer proves *"this log was not edited or reordered"* but
**not** *"this log came from the device we issued"* — because anyone holding
that shared key could produce a valid chain from scratch. Proof of **origin**
requires a secret only the device has. That is Layer 3.

---

## 7. Layer 3 — ECDSA P-256 signing inside the TEE

**Attack it stops:** forged submissions and MitM tampering (STD **TC-03**).

### The problem

An attacker skips the app entirely. They read the API documentation and POST
handcrafted attendance records straight to the server. Or they intercept a
genuine submission in transit and alter it.

### The mechanism

At device binding, the app asks the **Android Keystore** to generate an
**ECDSA P-256 key pair** *inside the TEE*, marked **non-exportable**:

- The **private key** is generated inside the TEE and **never leaves it**. Not
  to the app, not to Android, not to a rooted attacker. The app can only ask
  the TEE *"sign these bytes for me"* and receive the signature back.
- The **public key** is sent to the server and stored against that device.

Every attendance record's canonical payload is then signed by that private
key. The server verifies the signature against the registered public key.

Because the private key is physically incapable of leaving the chip:

> **A valid signature can only have been produced by that specific physical
> device.**

**Implementation:** [`SignatureVerifier.php`](../backend/app/Services/Crypto/SignatureVerifier.php)
— uses PHP's built-in OpenSSL binding, no third-party crypto dependency.

### What the verifier actually checks

It is deliberately strict, in this order — each check has a distinct failure
reason so the audit log is precise:

1. The signature is valid base64 (`signature_not_base64`)
2. The stored public key is readable (`public_key_unreadable`)
3. The key is genuinely an **elliptic curve** key (`public_key_not_ec`)
4. It is on the **expected curve**, P-256 (`public_key_wrong_curve`)
5. The signature verifies against the canonical payload
   (`signature_mismatch`)

Step 4 matters more than it looks: accepting "any EC key" would let an
attacker present a key on a deliberately weak curve.

This is also why TC-03's second attempt fails. An attacker *can* generate
their own ECDSA keypair and produce a **structurally perfect** signature — it
just won't match the public key on file for that device. Structural validity
is not identity.

### The honest caveat: hardware backing is not enforced by default

Android reports each key's protection level. This project accepts
`STRONGBOX` and `TRUSTED_ENVIRONMENT` as genuinely hardware-backed. An
**emulator reports `SOFTWARE`** — no hardware protection at all.

The enforcement switch is `HRIS_REQUIRE_HARDWARE_KEYS`, and **it defaults to
`false`** so that local development on an emulator is not blocked.

> **This means that, as shipped in development, the hardware-backing claim is
> documented but not enforced.** Turning that flag on in production is what
> converts it from a claim into a guarantee. If a panel asks "how do you know
> the key is really in hardware?", the honest answer is: "Android attests the
> security level, we check it against an allowlist, and that check is gated
> behind a config flag that must be enabled for production — it is off in dev
> because emulators cannot provide hardware keys."

Anticipate this question. Answering it cleanly demonstrates you understand
your own threat model; being caught by it looks like you don't.

---

## 8. Layer 4 — Server-side verification

**The principle:** *never trust the client.*

Every value discussed above arrives from a device the server does not control.
The device could claim anything. So the server independently re-derives and
re-checks everything, and **the server's verdict is the only one that
counts**:

- It **recomputes** every HMAC from the canonical payload and its own copy of
  the device key — it does not trust the `hmac_hash` sent to it.
- It **re-verifies** every signature against the public key *it* has on file —
  not any key the request offers.
- It **cross-checks** the two clocks against the previous accepted record *it*
  stored.
- It records the outcome in `crypto_signatures.verified`, so a record's
  verification status is durable and auditable rather than a transient
  decision.

The `crypto_signatures` table mirrors the ERD's `tbl_crypto_signature`, with
a `unique` constraint on `attendance_id` enforcing the 1:1 relationship
(one attendance record, exactly one signature).

Note also that all four verifiers operate on **plain arrays, not Eloquent
models**, specifically so they are unit-testable with no database — which is
what makes TC-01/02/03 runnable as fast automated tests.

---

## 9. Device binding: the trust anchor

Every layer above ultimately rests on one question: **how did the server come
to trust this device's public key and HMAC secret in the first place?**

That is device binding (the *Foreman Device Binding* screen). It is a
one-time, **online**, authenticated enrolment:

1. The foreman signs in with credentials over HTTPS.
2. The app has the TEE generate a non-exportable P-256 key pair.
3. The app sends the **public** key plus the reported security level.
4. The server issues a per-device **HMAC secret** and stores the public key
   against that device.

From then on, the device can prove its identity offline, indefinitely,
without ever transmitting a secret again.

This is the trust anchor, and therefore **the most security-sensitive moment
in the whole system**. Everything else is only as trustworthy as this one
exchange. It is also why Layer 1's "first record has no baseline" gap is
acceptable: the baseline is established by an authenticated enrolment, not by
an unverified first record.

---

## 10. End-to-end: one attendance record

Putting it together. The foreman taps **"Present"** for a worker, offline:

**On the phone**

1. Read the wall clock → `time_in`.
2. Read `elapsedRealtime()` → `monotonic_timestamp`; read `boot_id`.
3. Fetch the last record's `hmac_hash` from local storage → `prev_hash`.
4. Build the **canonical payload** (fixed fields, fixed order, versioned).
5. Compute `HMAC-SHA256(device_secret, payload)` → `hmac_hash`.
6. Ask the **TEE** to sign the payload with the non-exportable private key →
   `ecdsa_signature`.
7. Write everything to the **local SQLCipher-encrypted** SQLite database and
   queue it for sync.

*No network was required for any of this.* That is the whole point.

**Later, when connectivity returns**

8. The sync engine (Phase 6) transmits the queued batch over HTTPS.
9. The server rebuilds the canonical payload from the submitted fields.
10. **Layer 2:** recompute the HMAC; verify `prev_hash` links to the last
    accepted record.
11. **Layer 3:** verify the ECDSA signature against the stored public key.
12. **Layer 1:** cross-check wall clock against monotonic clock versus the
    previous record.
13. All three pass → record accepted, `verified = true`.
    Any fail → rejected with a specific reason, and the audit trail records
    which layer caught it and where.

---

## 11. Mapping to the STD test cases

| Test | Attack simulated | Layer that catches it | Expected result |
|---|---|---|---|
| **TC-01** | Roll the device clock back two hours and log attendance | Layer 1 — monotonic cross-check | Rejected, `wall_clock_rolled_back`, with drift reported |
| **TC-02** | Edit a row in the local SQLite database directly | Layer 2 — HMAC chain | Row 1 accepted; tampered row and all rows after it rejected, with the break point identified |
| **TC-03** | Submit forged records to the API, then retry with a signature from a non-TEE keypair | Layer 3 — ECDSA verification | Both rejected: `signature_mismatch` (the key is not the enrolled one) |

These three are specified in [`docs/STD.md`](STD.md) with blank
Actual Result / Pass-Fail columns, to be completed at execution.

---

## 12. Limitations — state these before a panelist finds them

Being able to articulate what your system *doesn't* do is a sign of
engineering maturity, not weakness.

1. **It does not stop a foreman lying at tap time.** The engine protects
   records *after* creation. A foreman who marks an absent worker present
   creates a cryptographically perfect record of a false fact. Mitigation is
   procedural — supervision, spot audits, the executive analytics dashboard
   flagging improbable patterns — not cryptographic.

2. **Hardware backing is not enforced in development.**
   `HRIS_REQUIRE_HARDWARE_KEYS=false` by default (see
   [Layer 3](#7-layer-3--ecdsa-p-256-signing-inside-the-tee)). Must be enabled
   for production.

3. **A rooted device could tamper before signing.** The layers protect the
   record once created. Malware with sufficient privilege could theoretically
   feed false values *into* the signing step. It still cannot extract the key
   or sign off-device.

4. **A compromised HMAC secret weakens Layer 2 only.** The shared secret could
   forge a chain — but not a signature, since that needs the TEE-bound private
   key. This is exactly why the layers are stacked.

5. **Device loss requires revocation.** A stolen, unlocked, enrolled phone can
   produce valid records. This needs an operational process (revoke the device
   server-side), not more mathematics.

6. **The first record from a device cannot be clock-checked.** No baseline to
   compare against; mitigated by authenticated device binding.

---

## 13. Likely defense questions, with answers

**"Why not just use HTTPS?"**
HTTPS protects data *in transit*. Our threat is data *at rest on a device the
attacker controls*, for hours, before it ever transits. HTTPS does nothing
about a foreman editing the local database — and we use HTTPS as well.

**"Why both HMAC and ECDSA? Isn't that redundant?"**
They answer different questions. HMAC uses a key the server also holds, so it
proves *integrity* ("unedited") but not *origin* — anyone with the shared key
could forge it. ECDSA's private key never leaves the TEE, so it proves
*origin*. HMAC additionally gives us cheap chaining for detecting reordering
and deletion. Neither alone is sufficient.

**"Why not just trust the server's clock?"**
The records are created offline, sometimes hours before the server ever sees
them. There is no server clock available at creation time. The monotonic
counter is the only trustworthy time reference on an offline device.

**"What if someone just reboots to reset the monotonic clock?"**
`boot_id` changes on reboot, so the server sees a known boot boundary rather
than a suspicious jump. Continuity across the boundary is still enforced by
the hash chain's `prev_hash`, so a reboot cannot insert or discard history.

**"Could the foreman just uninstall and reinstall to start fresh?"**
That destroys the local chain, but the server retains the last accepted hash
for that device. The next batch's `prev_hash` won't match, and the break is
visible. Re-enrolment is an authenticated, auditable event.

**"Is this actually novel, or standard practice?"**
The primitives are all standard and deliberately so — inventing cryptography
is how projects get broken. What is purpose-built is the *combination*: a
monotonic-clock cross-check plus hash chaining plus TEE-bound signing, applied
to offline-first attendance capture in a construction-site context where the
recording device is controlled by an interested party.

---

## 14. Where the code lives

| Concern | File |
|---|---|
| Canonical payload (phone) | `mobile/src/crypto/payload.ts` |
| Canonical payload (server) | `backend/app/Services/Crypto/AttendancePayload.php` |
| Hash chain building + self-check | `mobile/src/crypto/hashChain.ts` |
| Hash chain verification | `backend/app/Services/Crypto/HashChainVerifier.php` |
| Clock cross-check | `backend/app/Services/Crypto/ClockIntegrityVerifier.php` |
| Signature verification | `backend/app/Services/Crypto/SignatureVerifier.php` |
| Tunable thresholds | `backend/config/crypto.php` |
| Signature ledger schema | `backend/database/migrations/*_create_crypto_signatures_table.php` |

Per CLAUDE.md §7, any change to the crypto engine **must** come with unit
tests. The payload implementations on both sides are additionally pinned to a
shared test vector — if they ever drift, the tests fail loudly instead of
every signature failing mysteriously in production.
