# Philippine Labor Law, Taxes & Statutory Contributions

**A plain-English guide to the legal rules the HRIS payroll engine must obey.**

This document explains the Philippine rules that govern how Arcenas Development
Corporation must pay its construction workers: the Labor Code premium-pay
rules, the regional minimum wage, 13th month pay, the three mandatory
statutory contributions (SSS, PhilHealth, Pag-IBIG), and withholding tax.

Audience: the capstone team building the Phase 8 payroll engine, and the panel.
No prior knowledge of Philippine labor law assumed — every agency, law type and
acronym is defined.

---

## ⚠️ Read this before using any number in this document

**The structure of the law is stable. The rates are not.**

Contribution rates, tax brackets, minimum wages and contribution ceilings
change by government issuance — sometimes annually, sometimes mid-year. Every
numeric rate in this document is marked with its basis and an
**`[VERIFY]`** flag.

**Before implementing Phase 8, each `[VERIFY]` figure must be confirmed against
the issuing agency's current official circular.** Sources are listed in
[§14](#14-official-sources-to-verify-against).

This is not a disclaimer for form's sake. A payroll system that silently uses a
superseded SSS table under-remits contributions, which is a real legal exposure
for the client. Treat stale rates as a defect, not a rounding error.

The design consequence runs through this whole document: **rates belong in
configuration with effective dates, never hardcoded in computation logic.**
See [§12](#12-what-this-means-for-the-hris-implementation).

---

## 1. Who makes these rules? (Agencies and law types)

### The agencies

| Acronym | Full name | What it governs here |
|---|---|---|
| **DOLE** | Department of Labor and Employment | Working hours, premium pay, holidays, leave, employment status. The labor-standards regulator. |
| **NWPC / RTWPB** | National Wages and Productivity Commission / Regional Tripartite Wages and Productivity Board | Sets **regional** minimum wages via *Wage Orders*. The RTWPB for Region VII covers Cebu. |
| **BIR** | Bureau of Internal Revenue | Income tax and withholding tax on wages. |
| **SSS** | Social Security System | Pension, sickness, maternity, disability, death benefits for private-sector workers. |
| **PhilHealth** | Philippine Health Insurance Corporation | National health insurance. |
| **Pag-IBIG / HDMF** | Home Development Mutual Fund | Housing fund and savings. ("Pag-IBIG" is the Filipino acronym; HDMF is the corporate name — same institution.)|

### The kinds of legal instrument

- **PD** — *Presidential Decree.* Issued by the President under martial-law-era
  legislative power. The Labor Code itself is a PD.
- **RA** — *Republic Act.* A law passed by Congress. Most amendments to labor
  and tax law are RAs.
- **Wage Order** — a regional issuance by an RTWPB setting minimum wage for
  that region. Changes most frequently of anything here.
- **Circular / Advisory** — an agency's implementing instruction (e.g. an SSS
  Circular publishing a new contribution table). This is usually what actually
  changes a rate.
- **IRR** — *Implementing Rules and Regulations.* The detailed rules an agency
  writes to operationalise a law.

---

## 2. Citation correction: it is PD 442, not RA 442

> **The Labor Code of the Philippines is Presidential Decree No. 442** (1974),
> as amended. It is **not** a Republic Act.

`CLAUDE.md` §3, `backend/config/payroll.php`, and the SPMP/SRS currently cite
**"RA 442"**. That is incorrect and should be fixed before submission — in a
project whose stated purpose is *compliance with the Philippine Labor Code*,
miscitating the Labor Code is an easy and embarrassing catch for a panelist.

Correct forms to use:

- *Presidential Decree No. 442, as amended* — formal
- *PD 442 (Labor Code of the Philippines)* — first mention
- *the Labor Code* — thereafter

**A second citation caution.** DOLE renumbered the Labor Code's articles in
*Department Advisory No. 01, Series of 2015*. Most references, textbooks and
the DOLE Handbook still use the **original** numbering (Art. 83, 86, 87, 93,
94 — the numbers this project uses). That is fine and conventional, but write
*"as amended and renumbered"* so the citation is unambiguous rather than
looking like it ignores the renumbering. **`[VERIFY]`** the exact
original→renumbered mapping with your adviser if the documents must cite the
new numbers.

---

## 3. Normal hours, and what counts as work

| Rule | Provision | Detail |
|---|---|---|
| Normal working day | Art. 83 | **8 hours.** Beyond that is overtime. |
| Meal period | Art. 85 | At least **60 minutes** for a regular meal — **unpaid and not counted as hours worked**. |
| Weekly rest day | Art. 91 | At least **24 consecutive hours** of rest after every 6 consecutive working days. |
| Coverage exclusions | Art. 82 | Managerial employees, **field personnel**, and others are excluded from the hours/premium-pay rules. |

Two of these matter more than they look for a construction HRIS:

**The unpaid meal break.** If the app records a 7:00 AM time-in and a 5:00 PM
time-out, that is 10 clock hours but **9 payable hours** (8 regular + 1
overtime) once the 1-hour meal break is excluded. A payroll engine that pays 10
hours overpays every worker every day. **The current Phase 4 attendance capture
records `time_in` and `time_out` only — there is no break tracking, so Phase 8
must decide and document how the meal period is deducted** (typically a fixed
1-hour deduction for shifts over 6 hours, or an explicit company policy).

**"Field personnel" exclusion.** Art. 82 excludes workers "whose actual hours
of work in the field cannot be determined with reasonable certainty." A
construction company might argue site workers are field personnel — but the
entire point of this HRIS is that it *does* determine their hours with
certainty, via cryptographically verified roll call. **`[VERIFY]` with the
client/adviser**, because the answer decides whether premium pay applies at
all. Worth noting in the defense: by making hours determinable, the system
arguably moves workers *out* of the exclusion and *into* full premium-pay
coverage.

---

## 4. Premium pay: the rates and how they stack

This is the heart of the payroll engine.

### The four base premiums (what the project has now)

| Premium | Provision | Rate | Meaning |
|---|---|---|---|
| **Overtime** | Art. 87 | **+25%** → ×1.25 | Hours beyond 8 on an ordinary day |
| **Night shift differential** | Art. 86 | **+10%** → ×1.10 | Each hour worked between **10:00 PM and 6:00 AM** |
| **Rest day / special day** | Art. 93 | **+30%** → ×1.30 | Work on the employee's rest day |
| **Regular holiday** | Art. 94 | **×2.00** | Work on a regular holiday (first 8 hours) |

These four match `config/payroll.php` and are correct. **But they are not
sufficient**, for two reasons covered next.

### Gap 1 — there are *two* kinds of holiday, paid very differently

Philippine law distinguishes them, and the difference is large:

| Holiday type | If **not** worked | If worked (first 8h) | Examples |
|---|---|---|---|
| **Regular holiday** | **Paid 100%** (paid even for not working) | **200%** | New Year's Day, Araw ng Kagitingan, Maundy Thursday, Good Friday, Labor Day, Independence Day, National Heroes' Day, Bonifacio Day, Christmas Day, Rizal Day |
| **Special (non-working) day** | **Unpaid** ("no work, no pay") | **130%** | Ninoy Aquino Day, All Saints' Day, Immaculate Conception, last day of the year |

> **`config/payroll.php` has a single `'holiday' => 2.00`.** Applying 200% to a
> special non-working day **overpays by 70 percentage points**; treating a
> regular holiday as unpaid when not worked **underpays a full day's wage** to
> every worker. Both are compliance failures in opposite directions.
>
> Phase 8 needs **two** holiday rates plus a holiday *calendar* that records
> each date's type. The calendar is itself an annual issuance — the President
> proclaims the following year's holidays by Executive Order, and dates move
> (especially Eid'l Fitr and Eid'l Adha, which follow the Islamic calendar).
> **`[VERIFY]` annually.**

### Gap 2 — premiums compound; they do not simply add

This is where payroll engines most often go wrong. Working overtime *on a rest
day* is not 1.25 + 1.30. The rest-day rate establishes the applicable hourly
rate, and overtime adds **30%** on top of *that* (Art. 87 sets OT on a rest day
or holiday at +30%, not +25%).

**Reference matrix** — multiply the **basic hourly rate** by:

| Scenario | First 8 hours | Overtime hours |
|---|---|---|
| Ordinary day | 1.00 | **1.25** |
| Rest day | 1.30 | **1.69** (1.30 × 1.30) |
| Special non-working day | 1.30 | **1.69** |
| Special day **falling on** rest day | 1.50 | **1.95** (1.50 × 1.30) |
| Regular holiday | 2.00 | **2.60** (2.00 × 1.30) |
| Regular holiday **on** rest day | 2.60 | **3.38** (2.60 × 1.30) |
| Double regular holiday | 3.00 | **3.90** |
| Double regular holiday on rest day | 3.90 | **5.07** |

**`[VERIFY]` this whole matrix against the current DOLE *Handbook on Workers'
Statutory Monetary Benefits*** — it is the authoritative practical reference and
is reissued periodically.

**Night differential stacks on top of all of the above.** It is +10% of *the
applicable hourly rate*, not +10% of the basic rate. An overtime hour at 2 AM
on a regular holiday is:

```
basic hourly × 2.60 (holiday OT) × 1.10 (night diff)  =  basic hourly × 2.86
```

Getting this ordering right — **apply day-type first, then overtime, then night
differential** — is the single most important correctness requirement in the
payroll engine, and the thing a knowledgeable panelist is most likely to probe.

### Where the hourly rate comes from

Workers here are **daily-paid** (`Employee.daily_rate`), which is standard for
construction. So:

```
basic hourly rate = daily_rate ÷ 8
```

For monthly-paid staff the daily rate derives from a **divisor** reflecting how
many days a year the salary is deemed to cover (e.g. 313 for those working 6
days/week, 261 for 5 days/week). **`[VERIFY]` the client's actual divisor** if
the system must handle monthly-paid employees — the project's data model
(`daily_rate`) currently assumes daily-paid throughout.

---

## 5. Minimum wage

Set **regionally** by Wage Orders, not nationally. Arcenas is in Cebu →
**Region VII (Central Visayas)**.

- `config/payroll.php` holds **₱501.00/day** as `regional_minimum_wage`
  **`[VERIFY]`** — this was correct for the Region VII non-agriculture rate at
  the time it was recorded, but wage orders are among the most frequently
  revised figures in this document.
- Rates vary **within** a region by sector (non-agriculture / agriculture) and
  sometimes by business size. **`[VERIFY]` which classification applies** to
  construction workers at this client.

The system already enforces this as a floor on `daily_rate` in employee
validation, correctly config-driven rather than hardcoded.

**Tax consequence, and it is a big one:** statutory **minimum wage earners are
exempt from income tax** — and so are their holiday pay, overtime pay, night
shift differential and hazard pay (RA 9504). Since many construction workers
sit at or near minimum wage, **a large fraction of this client's workforce may
owe zero withholding tax.** The payroll engine must handle this explicitly
rather than computing tax and arriving at zero by accident. See
[§10](#10-withholding-tax-on-wages).

---

## 6. 13th month pay

- **Basis:** **PD 851**. Mandatory for all rank-and-file employees, regardless
  of position or how they are paid, who worked **at least one month** in the
  calendar year.
- **Amount:** **total basic salary earned during the year ÷ 12** — pro-rated
  for partial years. It is *not* automatically a full month's pay.
- **"Basic salary" excludes** overtime, holiday premium, night differential,
  allowances and other monetary benefits, unless the employer has integrated
  them by policy or CBA.
- **Deadline:** on or before **24 December**.
- **Tax:** 13th month pay plus "other benefits" is **non-taxable up to
  ₱90,000** **`[VERIFY]`**; any excess is taxable.

For a daily-paid construction workforce with variable days worked, the ÷12
of *actual basic earnings* rule matters a lot — it depends directly on the
attendance data this system captures, which is a nice point to make about the
value of accurate attendance records.

---

## 7. Service Incentive Leave

- **Basis:** Art. 95.
- **Entitlement:** **5 days with pay** per year, after **one year of service**.
- **Convertible to cash** if unused at year end.
- Employers already granting ≥5 days of paid leave (vacation/sick) are deemed
  compliant.

Relevant to the Phase 9 Leave module: the 5-day SIL is the statutory *floor*,
and unused balance is a **monetary liability**, so leave balances feed payroll.

---

## 8. The three mandatory statutory contributions

Every private employer must deduct the **employee share** from wages, add the
**employer share**, and remit both. Employer share is a company cost, never
deducted from the worker.

All three are computed on **monthly** compensation, which needs care in a
**daily-paid, semi-monthly-paid** setting — you must aggregate the month's
earnings to find the bracket, then decide how to split the deduction across pay
periods. **This is a design decision Phase 8 must make explicitly.**

### 8.1 SSS — Social Security System

- **Basis:** **RA 11199** (Social Security Act of 2018).
- **Mechanism:** contributions are based on the **MSC** (*Monthly Salary
  Credit*) — a bracketed figure from the official SSS contribution table, not a
  raw percentage of actual pay. You locate the employee's earnings in the table
  and read off the amounts.
- **Rate:** RA 11199 mandated a **stepped increase** to reach **15%** of MSC,
  split roughly **employer 10% / employee 5%** **`[VERIFY]`**.
- **MSC floor and ceiling** apply — earnings below the floor use the floor;
  above the ceiling use the ceiling. **`[VERIFY]` current values.**
- Above a threshold MSC, part of the contribution goes to the **WISP**
  (*Worker's Investment and Savings Program*), a mandatory provident fund.
- Employers also pay a small separate **EC** (*Employees' Compensation*)
  premium for work-related injury/illness — **especially relevant to
  construction**.

> **Implement SSS as a lookup table with effective dates, not a formula.** It is
> genuinely bracketed, and the table is republished by SSS Circular.

### 8.2 PhilHealth — national health insurance

- **Basis:** **RA 11223** (Universal Health Care Act).
- **Mechanism:** a **percentage** premium of monthly basic salary, split
  **50/50** between employer and employee.
- **Rate:** **5%** **`[VERIFY]`** — the UHC Act legislated a yearly escalation
  schedule, and actual implementation has been adjusted by PhilHealth Circular
  more than once, so this must be confirmed against the current circular.
- **Income floor and ceiling** bound the premium. **`[VERIFY]`**

### 8.3 Pag-IBIG / HDMF — housing fund

- **Basis:** **RA 9679** (HDMF Law of 2009).
- **Employee share:** **1%** of monthly compensation if ≤ ₱1,500; **2%** if
  above. **`[VERIFY]`**
- **Employer share:** **2%**. **`[VERIFY]`**
- **Capped** by a maximum fund salary, so contributions plateau above it.
  **`[VERIFY]` — this cap was revised relatively recently and is the single
  figure in this document I would double-check first.**

### Summary

| | Basis | Employee share | Employer share | Computed from |
|---|---|---|---|---|
| **SSS** | RA 11199 | ~5% of MSC `[VERIFY]` | ~10% of MSC + EC `[VERIFY]` | Bracketed **table** lookup |
| **PhilHealth** | RA 11223 | half of premium `[VERIFY]` | half of premium `[VERIFY]` | **Percentage**, floor/ceiling |
| **Pag-IBIG** | RA 9679 | 1–2% `[VERIFY]` | 2% `[VERIFY]` | **Percentage**, capped |

---

## 9. Order of computation — how a payslip is actually built

Sequence matters, because tax is computed *after* statutory contributions are
deducted.

```
STEP 1  GROSS EARNINGS
        regular hours           × basic hourly × 1.00
      + overtime hours          × basic hourly × applicable OT multiplier
      + rest day / holiday hours× basic hourly × applicable day multiplier
      + night differential      +10% of applicable rate for 10PM–6AM hours
      + holiday pay for unworked regular holidays
      + allowances / other taxable pay
      ─────────────────────────────────────────────────────────
      = GROSS PAY

STEP 2  MANDATORY CONTRIBUTIONS (employee share only)
      − SSS employee share
      − PhilHealth employee share
      − Pag-IBIG employee share
      ─────────────────────────────────────────────────────────
      = TAXABLE INCOME          ← contributions are deducted BEFORE tax

STEP 3  WITHHOLDING TAX
      − withholding tax on taxable income
        (₱0 if a statutory minimum wage earner — see §10)

STEP 4  OTHER DEDUCTIONS
      − cash advances, loans (SSS/Pag-IBIG salary loans), union dues,
        tardiness/undertime, uniform, etc.
      ─────────────────────────────────────────────────────────
      = NET PAY  ("take-home")
```

The `payrolls` / `payroll_details` tables already model
`gross_pay` → `total_deductions` → `net_pay` with the hour-type breakdown,
which fits this sequence. What is missing is any column for the **individual**
statutory contributions and tax — currently `payroll_details` has a single
`deductions` field. **A payslip must itemise SSS, PhilHealth, Pag-IBIG and
withholding tax separately**, both because workers are entitled to see them and
because the company must reconcile remittances per agency. Flag this to Efren
as a schema gap for Phase 8.

---

## 10. Withholding tax on wages

- **Basis:** the **NIRC** (*National Internal Revenue Code*), as amended by
  **RA 10963 — the TRAIN Law** (*Tax Reform for Acceleration and Inclusion*),
  with rates further reduced from **1 January 2023**.
- **Mechanism:** the employer withholds tax each pay period and remits it to
  the BIR — the worker does not pay it themselves. Employers use the BIR
  withholding tax tables (daily / weekly / semi-monthly / monthly) matching
  their payroll frequency.

**Annual graduated rates `[VERIFY]` against the current BIR issuance:**

| Annual taxable income | Tax |
|---|---|
| Not over ₱250,000 | **0%** |
| Over ₱250,000 – ₱400,000 | 15% of excess over ₱250,000 |
| Over ₱400,000 – ₱800,000 | ₱22,500 + 20% of excess over ₱400,000 |
| Over ₱800,000 – ₱2,000,000 | ₱102,500 + 25% of excess over ₱800,000 |
| Over ₱2,000,000 – ₱8,000,000 | ₱402,500 + 30% of excess over ₱2,000,000 |
| Over ₱8,000,000 | ₱2,202,500 + 35% of excess over ₱8,000,000 |

### Two exemptions that dominate this client's situation

1. **The ₱250,000 zero bracket.** A worker at ₱501/day working ~313 days earns
   roughly ₱157,000/year — **comfortably inside the 0% bracket.** Most of this
   workforce likely owes no income tax at all.

2. **Minimum wage earner exemption (RA 9504).** A statutory MWE is exempt on
   their minimum wage **and** on holiday pay, overtime pay, night shift
   differential and hazard pay — so even premium-heavy months stay exempt.

**Design implication:** the engine should determine MWE status and the annual
projection explicitly, and record *why* tax is zero. "Tax came out as ₱0"
because a formula happened to yield zero is not auditable; "exempt: statutory
minimum wage earner per RA 9504" is.

---

## 11. Construction-specific employment realities

The `employment_status` field already carries **Probationary / Regular /
Project-based / Seasonal**, which matters legally:

- **Project-based employment** is legitimate in construction — engagement is
  tied to a specific project, and employment ends with it (DOLE **Department
  Order No. 19, s. 1993** covers the construction industry specifically
  **`[VERIFY]`**). But project-based workers are **still entitled to premium
  pay, statutory contributions, 13th month pay and SIL.** Project-based is not
  a route around the monetary benefits.
- **Repeated re-engagement across projects** can ripen into regular employment
  — a legal risk the company carries, not a payroll computation, but worth
  understanding as context for why accurate deployment records matter.

---

## 12. What this means for the HRIS implementation

Concrete gaps between the law above and the current codebase.

### Already right

- `regional_minimum_wage` and the four base multipliers live in
  `config/payroll.php`, not in computation logic — exactly the correct pattern,
  and the config's own comment says why.

### Must be added for Phase 8

1. **Split the holiday rate in two** — `regular_holiday` (2.00) and
   `special_non_working_day` (1.30). One `holiday` key cannot express the law.
2. **A holiday calendar table** recording each date and its type, since
   holidays are proclaimed annually by Executive Order and some dates move.
3. **The full compounding matrix** from [§4](#4-premium-pay-the-rates-and-how-they-stack),
   with computation ordered *day-type → overtime → night differential*.
4. **Meal-period handling** — the current attendance model has no break
   capture, so the deduction policy must be explicit and documented.
5. **SSS contribution table** as effective-dated bracket data, not a formula.
6. **PhilHealth and Pag-IBIG rates**, with floors, ceilings and caps.
7. **BIR withholding tax brackets**, plus explicit MWE-exemption handling.
8. **Itemised deduction columns** on `payroll_details` — SSS, PhilHealth,
   Pag-IBIG and tax must each be visible, not merged into one `deductions`
   figure ([§9](#9-order-of-computation--how-a-payslip-is-actually-built)).
9. **13th month pay** accrual from actual basic earnings ÷ 12.

### Effective dating — the design point worth getting right

Every rate here can change *mid-year*. If payroll for March is re-run in
November — for a correction, an audit, or a dispute — it must use **the rates in
force in March**, not today's.

So rate configuration should be **effective-dated**, and a payroll run should
resolve its rates by the **period being paid**, not by the current date. A flat
config value cannot express this. Getting it wrong means historical payroll
silently changes when a rate is updated, which destroys auditability — and
auditability is the whole premise of this project.

This also makes a good defense answer: it shows the compliance thinking extends
past "we put the number in a config file."

### Testing

Per CLAUDE.md §7, payroll logic **must** have unit tests, and STD **TC-06**
covers holiday payroll computation. Beyond TC-06, the compounding matrix
deserves a test case per row — those are the combinations real payroll
engines get wrong, and they are cheap to assert.

---

## 13. Worked examples

Basic daily rate **₱600.00** → basic hourly **₱75.00**. Illustrative only;
verify rates before relying on any figure.

### A — Ordinary day, 10 hours worked (7 AM–5 PM, 1h unpaid meal)

```
Payable hours: 9  (10 clock hours − 1h meal)
Regular   8h × ₱75.00 × 1.00 =  ₱600.00
Overtime  1h × ₱75.00 × 1.25 =   ₱93.75
                        GROSS  =  ₱693.75
```

### B — Regular holiday, 8 hours

```
8h × ₱75.00 × 2.00 = ₱1,200.00
```
(Had the worker *not* worked, they would still be paid ₱600.00 — 100% — because
a regular holiday is paid whether worked or not.)

### C — Special non-working day, 8 hours

```
8h × ₱75.00 × 1.30 = ₱780.00
```
(Had they not worked: **₱0** — "no work, no pay" applies to special days.)
**Contrast with B — this is exactly the distinction the single `holiday => 2.00`
config key cannot make.**

### D — Regular holiday, 10 hours, 2 of them after 10 PM

```
First 8h      8h × ₱75.00 × 2.00               = ₱1,200.00
OT, daytime   0h
OT hours      2h × ₱75.00 × 2.60               =   ₱390.00
Night diff    2h × ₱75.00 × 2.60 × 0.10        =    ₱39.00
                                        GROSS  = ₱1,629.00
```
Note the night differential is 10% of the **holiday-overtime** rate, not 10% of
the basic rate — ₱39.00, not ₱15.00.

---

## 14. Official sources to verify against

Do not rely on this document, blog posts, or a payroll vendor's summary page
for rates. Use the issuing agency:

| For | Source |
|---|---|
| Labor Code text, premium pay, holidays, leave | **DOLE** — *Handbook on Workers' Statutory Monetary Benefits* (current edition) |
| Region VII minimum wage | **NWPC / RTWPB Region VII** — current Wage Order |
| Holiday dates and types for the year | the year's **Presidential Proclamation / Executive Order** on holidays |
| SSS contribution table | **SSS** — current Contribution Schedule Circular |
| PhilHealth premium rate, floor/ceiling | **PhilHealth** — current Premium Contribution Circular |
| Pag-IBIG rates and maximum fund salary | **Pag-IBIG / HDMF** — current Circular |
| Withholding tax tables and brackets | **BIR** — current Revenue Regulation / withholding tax tables |
| Construction industry employment rules | **DOLE** — Department Order No. 19, s. 1993 |

**Recommended for the capstone:** record, for each rate you implement, the
issuance number and its effective date — in the config file itself and in the
SDD. That way the panel can see the figure is sourced rather than assumed, and
whoever maintains this after the defense knows exactly what to re-check.

---

## 15. Quick reference — acronyms

| Acronym | Meaning |
|---|---|
| **BIR** | Bureau of Internal Revenue — tax authority |
| **CBA** | Collective Bargaining Agreement |
| **DO** | Department Order (a DOLE issuance) |
| **DOLE** | Department of Labor and Employment |
| **EC** | Employees' Compensation (SSS work-injury programme) |
| **HDMF** | Home Development Mutual Fund — Pag-IBIG's corporate name |
| **IRR** | Implementing Rules and Regulations |
| **MSC** | Monthly Salary Credit — the bracketed basis for SSS contributions |
| **MWE** | Minimum Wage Earner — income-tax-exempt status |
| **NIRC** | National Internal Revenue Code — the tax code |
| **NSD** | Night Shift Differential |
| **NWPC** | National Wages and Productivity Commission |
| **OT** | Overtime |
| **Pag-IBIG** | *Pagtutulungan sa Kinabukasan: Ikaw, Bangko, Industriya at Gobyerno* — the housing fund |
| **PD** | Presidential Decree |
| **PhilHealth** | Philippine Health Insurance Corporation |
| **RA** | Republic Act |
| **RTWPB** | Regional Tripartite Wages and Productivity Board |
| **SIL** | Service Incentive Leave — the 5-day statutory leave |
| **SSS** | Social Security System |
| **TRAIN** | Tax Reform for Acceleration and Inclusion (RA 10963) |
| **UHC** | Universal Health Care Act (RA 11223) |
| **WISP** | Worker's Investment and Savings Program (SSS provident fund) |
