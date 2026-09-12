# SPMP — Draft response to Dean's feedback

Working document. Two items were raised:

1. *"Provide an overview of what the SPMP is all about and how is it related to your project, or you may provide rationale and statement of the problem."*
2. *"Not following correct line spacing and most paragraphs don't have a line space before it."*

---

## Item 1 — Overview of the SPMP

**Why it drew the comment:** the existing §1 describes the *system* and the *project*. The Dean asked what the *document* is, and for the rationale / statement of the problem. Neither is currently present.

**The fix:** add two paragraphs at the front of §1 — one saying what the SPMP is, one giving the statement of the problem — then keep the four existing paragraphs as they are. The section then reads: what this document is → why the project exists → what the system is → how it is built → what the work covers.

Below is the complete replacement for §1 Overview, ready to paste.

---

### ▼ NEW — paragraph 1 (what the SPMP is, and how it relates to the project)

> This Software Project Management Plan (SPMP) describes how the Human Resource Information System (HRIS) for Arcenas Development Corporation will be planned, organized, developed, monitored, and delivered. It serves as the main management reference for the project. It identifies the project deliverables, assigns the roles and responsibilities of each member of the development team, presents the project schedule and the work breakdown structure, and defines how requirements, schedule, budget, quality, and risks are controlled throughout development. The Software Requirements Specification (SRS) describes what the system must do and the Software Design Description (SDD) describes how it is designed, while this document describes how the work of building the system is managed.

### ▼ NEW — paragraph 2 (rationale and statement of the problem)

> Arcenas Development Corporation currently records the daily attendance of its construction workers manually on paper forms at each project site. Many of these sites are located in remote areas with limited or no internet connection, so the attendance records are collected and encoded by hand, often several days after the work has been done. This delays payroll preparation, increases the chance of encoding errors, and makes it difficult to confirm whether the recorded time is accurate or has been altered. Payroll computation must also follow the premium rates required by the Philippine Labor Code, which is hard to apply consistently when it is done manually across several sites and pay periods. The HRIS project was undertaken to address these problems.

### ▽ EXISTING — paragraph 3 (unchanged)

> The Human Resource Information System (HRIS) for Arcenas Development Corporation is a web-based and mobile-enabled system developed to improve the organization, management, and monitoring of employee-related information and human resource processes. The project aims to provide a centralized platform for managing employee records, attendance, payroll, leave, user accounts, and HR-related reports.

### ▽ EXISTING — paragraph 4 (unchanged, but see optional edit below)

> The system consists of a web-based HRIS for centralized human resource management and an offline-first React Native mobile application designed for site foremen working in construction sites. The mobile application allows site foremen to record crew attendance through a digital checklist even when an internet connection is unavailable. Attendance records are stored securely on the mobile device and automatically synchronized with the central HRIS when connectivity is restored.

### ▽ EXISTING — paragraph 5 (unchanged)

> The project will be developed using an Agile Software Development approach, allowing the development team to work through multiple iterations of planning, development, testing, evaluation, and refinement. The system will use React.js for the web frontend, React Native for the mobile application, Laravel for backend services, MySQL for the central database, and SQLite for local mobile storage.

### ▽ EXISTING — paragraph 6 (unchanged)

> The project will involve requirements analysis, system and database design, development of the core HRIS modules, development of the mobile attendance application, integration and synchronization, security implementation, testing, documentation, and final deployment. The project is intended to provide Arcenas Development Corporation with a more organized, reliable, secure, and efficient solution for managing human resource and construction-site attendance processes.

---

### Two optional edits worth considering

**(a) Close the loop on tampering.** The new problem statement says recorded time can be altered without detection, but none of the existing paragraphs say how the system prevents that — and cryptographic attendance integrity is the project's headline feature. Consider appending one sentence to paragraph 4:

> …automatically synchronized with the central HRIS when connectivity is restored. **Each attendance record is also protected by a cryptographic signature generated on the device, which the central system verifies before accepting the record, so that any alteration can be detected.**

**(b) Name the encryption.** Paragraph 4 says records are "stored securely on the mobile device" and paragraph 5 says "SQLite for local mobile storage". The actual stack is SQLite with SQLCipher (AES-256). Consider changing paragraph 5's ending to:

> …MySQL for the central database, and SQLite with SQLCipher encryption for local mobile storage.

Both are accurate to the fixed technology stack and to the SDD. They also give the panel something concrete to ask about, which is usually an advantage.

---

## Item 2 — Line spacing and space before paragraphs

### What's actually wrong

The SPMP has explicit line spacing set on only **67 of its 767 text paragraphs**. The other 700 inherit Word's default — single spacing, no space before. For comparison, the SRS has 1.5 spacing on 210 of 215 paragraphs, so the SRS is the correct model and the SPMP is the outlier.

### Target settings

| Setting | Value |
|---|---|
| Line spacing | **1.5 lines** |
| Spacing **before** | **12 pt** (one line) |
| Spacing after | 0 pt |
| Alignment | Justified |

### How to apply in Word

1. Click in the body text, then **Home → Select → Select All Text With Similar Formatting** (or select the body sections manually — do *not* use Ctrl+A, see the exclusions below).
2. Open the Paragraph dialog (**Home → Paragraph → dialog launcher**, or right-click → Paragraph).
3. Set **Line spacing: 1.5 lines**, **Spacing Before: 12 pt**, **After: 0 pt**, **Alignment: Justified**.
4. Click OK.

### Do NOT apply it to

- **Anything inside a table.** Tables 1.0–8.0 have 8, 33, 7, 7, 26, 73, 9, and 15 rows. Applying 1.5 spacing plus 12 pt before to every cell will inflate the Work Activities table enormously and push it across several extra pages.
- **The List of Figures / List of Tables entries.** They sit in text boxes with manual dot leaders; changing spacing there breaks the alignment of the dots.
- **Empty spacer paragraphs**, which would otherwise each gain 12 pt.

---

## Knock-on effect — page numbers will shift

Reformatting takes the document from **36 to about 47 pages**, so every page number in the List of Figures and List of Tables becomes wrong. Update them after reformatting.

These are the values measured from a build with the settings above. **Re-check them against your own copy after you apply the formatting** — if your spacing differs even slightly the pagination will too.

| Entry | Currently says | Should be |
|---|---|---|
| Figure 1.0 External Structure | 11 | **17** |
| Figure 2.0 Internal Structure | 12 | **18** |
| Figure 3.0 Feature Breakdown Structure | 14 | **21** |
| Table 1.0 Milestone Task | 6 | **9** |
| Table 2.0 Definition, Acronyms, and Abbreviations | 10 | **14** |
| Table 3.0 Roles and Responsibilities | 14 | **19** |
| Table 4.0 Staff and Person Involved in Project | 17 | **23** |
| Table 5.0 Work Plan | 20 | **27** |
| Table 6.0 Work Activities | 23 | **30** |
| Table 7.0 Methods and Techniques | 29 | **39** |
| Table 8.0 Tools | 30 | **40** |

Every number keeps the same digit count, so the dot leaders don't need adjusting — just overtype the number.

---

## Already applied to `docs/SPMP.md`

The three overview paragraphs are already in the markdown version, under `## Section 1: Overview`. Line spacing doesn't apply there.
