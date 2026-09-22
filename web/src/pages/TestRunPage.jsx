import { useState } from 'react'
import { Link, Navigate } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'

/**
 * STD acceptance-run checklist (TC-01–TC-05) for the tester to fill in
 * during the run. Standalone route on purpose: it prints cleanly with no
 * staff shell around it, and it needs no sidebar entry. HR/Admin only —
 * the procedures name server settings and test secrets.
 *
 * Everything persists to localStorage (`hris.testrun.v1.*`) so a reload or
 * a dead laptop battery loses nothing. Nothing here touches the API.
 */

const LS_PREFIX = 'hris.testrun.v1.'

function useStored(key, initial) {
  const [value, setValue] = useState(() => {
    try {
      const raw = localStorage.getItem(LS_PREFIX + key)
      return raw === null ? initial : JSON.parse(raw)
    } catch {
      return initial
    }
  })
  const set = (next) => {
    setValue(next)
    try {
      localStorage.setItem(LS_PREFIX + key, JSON.stringify(next))
    } catch {
      // storage blocked — the page still works for this session
    }
  }
  return [value, set]
}

function Step({ id, children }) {
  const [done, setDone] = useStored(`step.${id}`, false)
  return (
    <label className="flex cursor-pointer items-start gap-2.5 border-b border-neutral-200 py-2 text-sm">
      <input
        type="checkbox"
        checked={done}
        onChange={(e) => setDone(e.target.checked)}
        className="mt-1 h-4 w-4 flex-none"
      />
      <span className={done ? 'text-neutral-700 line-through' : ''}>{children}</span>
    </label>
  )
}

function Notes({ id, label }) {
  const [text, setText] = useStored(`notes.${id}`, '')
  return (
    <div className="mt-3 print:hidden">
      <label className="mb-1 block text-[11px] uppercase tracking-[.08em] text-neutral-700">{label}</label>
      <textarea
        value={text}
        onChange={(e) => setText(e.target.value)}
        rows={2}
        className="w-full border border-neutral-400 bg-canvas px-2.5 py-2 text-sm"
        placeholder="Observed values, filenames of screenshots…"
      />
    </div>
  )
}

function Verdict({ id }) {
  const [value, setValue] = useStored(`verdict.${id}`, 'unrun')
  return (
    <div className="mt-3 flex items-center gap-2 text-sm print:hidden">
      <span className="text-[11px] uppercase tracking-[.08em] text-neutral-700">Result</span>
      {['unrun', 'pass', 'fail'].map((v) => (
        <label key={v} className="flex cursor-pointer items-center gap-1.5 border border-neutral-400 px-2.5 py-1.5 capitalize">
          <input type="radio" checked={value === v} onChange={() => setValue(v)} />
          {v}
        </label>
      ))}
    </div>
  )
}

function Section({ n, title, children }) {
  return (
    <section className="mt-8 break-inside-avoid">
      <h2 className="border-b-2 border-ink pb-1 font-heading text-xl">
        {n} · {title}
      </h2>
      {children}
    </section>
  )
}

function Meta({ id, label, hint }) {
  const [value, setValue] = useStored(`meta.${id}`, '')
  return (
    <label className="block text-sm">
      <span className="mb-1 block text-[11px] uppercase tracking-[.08em] text-neutral-700">{label}</span>
      <input
        value={value}
        onChange={(e) => setValue(e.target.value)}
        placeholder={hint}
        className="w-full border border-neutral-400 bg-canvas px-2.5 py-2 print:border-0 print:px-0"
      />
    </label>
  )
}

const QUERIES = `-- One crew-day, everything the run touches. Run before and after each case.
SELECT attendance_id, employee_id, date, status, time_in, override_flag, sync_status
  FROM attendances WHERE date = '<YYYY-MM-DD>' ORDER BY attendance_id;
SELECT signature_id, attendance_id, verified FROM crypto_signatures
  WHERE attendance_id IN (SELECT attendance_id FROM attendances WHERE date = '<YYYY-MM-DD>');
SELECT audit_id, action_type, actor_id, description, timestamp FROM audit_logs
  WHERE DATE(timestamp) = '<YYYY-MM-DD>'
    AND (action_type LIKE 'ATTENDANCE_%'
      OR action_type IN ('FOREMAN_LATE_OVERRIDE', 'ACTING_FOREMAN_ASSIGNED'))
  ORDER BY audit_id;
SELECT device_id, security_level, last_chain_hash, last_synced_at FROM device_keys;`

export function TestRunPage() {
  const { user } = useAuth()
  const slug = user?.role?.slug
  if (slug !== 'hr' && slug !== 'admin') {
    return <Navigate to="/" replace />
  }

  return (
    <main className="mx-auto max-w-[720px] px-[22px] py-8">
      <div className="flex items-start justify-between gap-3 print:hidden">
        <Link to="/" className="text-[13px] text-primary-700 hover:underline">
          ← Back to the app
        </Link>
        <button
          type="button"
          onClick={() => window.print()}
          className="flex-none border border-neutral-400 px-3 py-2 text-[13px]"
        >
          Print
        </button>
      </div>

      <h1 className="mt-2 font-heading text-[28px] leading-tight">STD acceptance run — TC-01 to TC-05</h1>
      <p className="mt-1 text-sm text-neutral-700">
        Fill in during the run. Checkboxes, verdicts and notes save on this machine only. TC-06 is a
        separate web walkthrough. Order matters on Day 2: a rejection halts its phone until re-bind,
        so tampering goes last.
      </p>

      <div className="mt-4 grid grid-cols-2 gap-3">
        <Meta id="date" label="Run dates" hint="Day 1 … / Day 2 …" />
        <Meta id="people" label="Tester / fixer / reviewer" hint="Efren / Rusel / Jay Mark" />
        <Meta id="phones" label="Phones" hint="P1 (F1), P2 (F2) — models" />
        <Meta id="server" label="Laptop / server" hint="LAN IP, compose stack" />
      </div>

      <Section n="Day 0" title="Setup and the four open claims">
        <Step id="d0-hwkeys">
          api service has <code>HRIS_REQUIRE_HARDWARE_KEYS=true</code> and was restarted. Release APK
          on P1 and P2, signed in as F1/F2, both bound. Device &amp; Sync Health shows
          TRUSTED_ENVIRONMENT or STRONGBOX for each.
        </Step>
        <Step id="d0-coldstart">
          Offline cold start: roster loaded, airplane mode, force-stop, reopen — still signed in with
          the roster, roll call works, reconnects and syncs.
        </Step>
        <Step id="d0-5s">
          Sync under 5 s, three times: offline roll call, airplane mode off with a stopwatch running,
          note when the row's created_at appears on the server.
        </Step>
        <Step id="d0-killed">
          Killed-app limitation recorded: offline record, force-stop, reconnect, wait two minutes
          without opening — nothing syncs until reopen. That is the documented Phase 6 limitation.
        </Step>
        <Step id="d0-net">
          Same Wi-Fi for phones and laptop. Firewall allows 8090 and 8080. App Server field is
          http://&lt;laptop LAN IP&gt;:8090. Crew X deployed, led by F1, workers A–F; F2 leads no
          crew; engineer + HR accounts ready. Debug build sideloaded for TC-02 only.
        </Step>
        <Notes id="d0" label="Day 0 notes (timings, key levels)" />
      </Section>

      <Section n="Day 1 · TC-04" title="Late foreman override — about 09:20, P1, real clock">
        <Step id="tc04-offer">Roll call offers the late override after 07:15.</Step>
        <Step id="tc04-apply">Applied; marked A, B and C Present; synced.</Step>
        <Step id="tc04-expect">
          time_in 07:00 with captured_at around 09:20; override_flag = shift_credit; one
          FOREMAN_LATE_OVERRIDE audit row naming F1; listed on the web Overrides page.
        </Step>
        <Step id="tc04-evidence">Evidence: phone screenshot, DB snapshot, web screenshot.</Step>
        <Verdict id="tc04" />
        <Notes id="tc04" label="TC-04 notes" />
      </Section>

      <Section n="Day 1 · TC-05" title="Absent foreman re-assignment — about 10:00">
        <Step id="tc05-assign">Engineer reassigns Crew X to F2 on the web, until end of today.</Step>
        <Step id="tc05-p2">P2 refreshes its roster, sees Crew X, records D and E.</Step>
        <Step id="tc05-p1">
          Crew X is gone from F1's roster. Optional: a Crew X tap F1 recorded offline is refused at
          sync (not_crew_foreman) without halting P1.
        </Step>
        <Step id="tc05-expect">
          crews.regular_foreman_id is F1 with crews.acting_until set; ACTING_FOREMAN_ASSIGNED names
          the engineer. Cover ends at midnight — F1 has the crew back for Day 2.
        </Step>
        <Step id="tc05-evidence">Evidence: phone screenshots (P1 + P2), DB snapshot, web screenshot.</Step>
        <Verdict id="tc05" />
        <Notes id="tc05" label="TC-05 notes" />
      </Section>

      <Section n="Day 2 · TC-01" title="Clock rollback — P1, after 09:00">
        <Step id="tc01-setup">Airplane mode on, automatic time off.</Step>
        <Step id="tc01-a">Recorded Worker A.</Step>
        <Step id="tc01-back">Set the clock back two hours. No reboot — the check compares taps within one boot.</Step>
        <Step id="tc01-b">Recorded Worker B.</Step>
        <Step id="tc01-sync">Automatic time back on, airplane mode off, synced.</Step>
        <Step id="tc01-expect">
          A accepted. B committed with verified = false and an ATTENDANCE_CLOCK_FLAGGED audit row.
          B's monotonic_timestamp is greater than A's. The next tap still syncs — the chain
          continued.
        </Step>
        <Step id="tc01-evidence">Evidence: phone screenshot, DB snapshot.</Step>
        <Verdict id="tc01" />
        <Notes id="tc01" label="TC-01 notes" />
      </Section>

      <Section n="Day 2 · TC-03" title="MitM signature forgery — P1 online, Wi-Fi proxy at mitmproxy">
        <Step id="tc03-hold">Recorded Worker C; held the real request back at the proxy, saved and dropped it.</Step>
        <Step id="tc03-a">Copy (a), time_in changed only: rejected hmac_mismatch.</Step>
        <Step id="tc03-b">
          Copy (b), time_in changed + HMAC recalculated with the phone's secret: rejected at the
          signature check.
        </Step>
        <Step id="tc03-c">Copy (c), as (b) plus a laptop-made P-256 signature: rejected, wrong key.</Step>
        <Step id="tc03-unblock">Interception off; the phone's retry went through, C accepted.</Step>
        <Step id="tc03-replay">Optional: original sent again, rejected prev_hash_mismatch.</Step>
        <Step id="tc03-expect">
          Three ATTENDANCE_VERIFICATION_FAILED rows with those reasons; no stored row for any copy;
          the phone's chain survives, no re-bind.
        </Step>
        <Step id="tc03-evidence">Evidence: proxy logs, DB snapshot, audit rows.</Step>
        <Verdict id="tc03" />
        <Notes id="tc03" label="TC-03 notes" />
      </Section>

      <Section n="Day 2 · TC-02" title="Local database tampering — debug build on P1, last">
        <Step id="tc02-setup">
          Release app uninstalled, debug build on (Metro over USB), signed in as F1, re-bound — a
          fresh chain. Key read off the debug-only panel at the bottom of the Sync screen.
        </Step>
        <Step id="tc02-record">Airplane mode on; recorded D, E and F.</Step>
        <Step id="tc02-edit">
          Force-stopped; pulled hris_offline.db with -wal and -shm; changed E's time_in in
          attendance_events (epoch ms) without touching hashes; PRAGMA wal_checkpoint(TRUNCATE);
          pushed back only the main file; deleted -wal/-shm on the phone.
        </Step>
        <Step id="tc02-sync">Reopened, airplane mode off, synced.</Step>
        <Step id="tc02-expect">
          D accepted. E rejected hmac_mismatch, F chain_broken_upstream. No rows stored for E or F;
          two audit rows. Phone shows sync halted, keeps E and F locally.
        </Step>
        <Step id="tc02-cleanup">Cleaned up: debug build uninstalled, release reinstalled, F1 re-bound.</Step>
        <Step id="tc02-evidence">Evidence: DB snapshot, halt screenshot, audit rows.</Step>
        <Verdict id="tc02" />
        <Notes id="tc02" label="TC-02 notes" />
      </Section>

      <Section n="Reference" title="Database snapshots">
        <p className="mt-2 text-sm text-neutral-700">
          Run before and after each case, one crew-day at a time:
        </p>
        <pre className="mt-2 overflow-x-auto border border-neutral-300 bg-canvas p-3 text-xs leading-relaxed">
          {QUERIES}
        </pre>
      </Section>

      <Section n="Afterwards" title="Recording the run">
        <Step id="after-std">Date, Actual Result, Pass/Fail and Comments filled for TC-01–TC-05 in both STD.md and STD.docx.</Step>
        <Step id="after-tasks">TC line ticked in TASKS.md Phase 10. TC-06 stays open (separate web walkthrough).</Step>
        <Step id="after-prod">HRIS_REQUIRE_HARDWARE_KEYS=true kept for the defense demo and added to production.</Step>
      </Section>
    </main>
  )
}
