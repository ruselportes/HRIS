# HRIS — Human Resource Information System

Capstone project for **Arcenas Development Corporation**, a construction company. Digitizes and centralizes HR + payroll operations, with a focus on:

- Recording daily attendance for construction crews across multiple sites — **including remote sites with no internet connectivity**.
- Preventing timestamp manipulation on field devices via cryptographic, hardware-backed signing.
- Automating payroll computation in compliance with the **Philippine Labor Code** (RA 442).

See [`CLAUDE.md`](CLAUDE.md) for full project context, and [`docs/TASKS.md`](docs/TASKS.md) for current progress.

## Tech Stack

| Layer | Technology |
|---|---|
| Backend / API | Laravel 13 (PHP 8.4) |
| Web frontend | React.js + TailwindCSS v4 (Vite) |
| Mobile app | React Native (Android primary, iOS secondary) |
| Central DB | MySQL 8.0 / MariaDB (local dev via XAMPP) |
| Mobile local DB | SQLite (SQLCipher/AES-256 planned for Phase 5) |
| Crypto | HMAC-SHA256 hash chaining, ECDSA P-256 signing |
| Hardware security | Android Keystore TEE / iOS Secure Enclave |

## Repository Structure

```
capstone_hris/
├── docs/           SPMP, SRS, ERD (.drawio + reference notes), design prototypes, task tracker
├── backend/        Laravel API (RBAC, Employee, Crew, Attendance, Payroll, Crypto, Sync services)
├── web/            React + Vite + TailwindCSS admin/HR/executive portal
└── mobile/         React Native offline attendance app for site foremen
```

## Getting Started

### Backend (Laravel)

```bash
cd backend
composer install
cp .env.example .env   # configure DB_DATABASE=hris, DB_USERNAME, DB_PASSWORD
php artisan key:generate
php artisan migrate
php artisan serve
```

### Web (React)

```bash
cd web
npm install
npm run dev
```

### Mobile (React Native)

```bash
cd mobile
npm install
npx react-native run-android
```

Requires `ANDROID_HOME` set and a JDK installed for Android builds.

## Documentation

- [`docs/SPMP.md`](docs/SPMP.md) — Software Project Management Plan
- [`docs/SRS.md`](docs/SRS.md) — Software Requirements Specification
- [`docs/HRIS_ERD.drawio`](docs/HRIS_ERD.drawio) / [`docs/HRIS_ERD_reference.md`](docs/HRIS_ERD_reference.md) — Entity-Relationship Diagram (13-table schema)
- [`docs/prototypes/`](docs/prototypes/) — UI screen prototypes (Claude Design exports)
- [`docs/TASKS.md`](docs/TASKS.md) — phased task tracker (10 phases, 10% each)

## Team

| Role | Member |
|---|---|
| Project Manager | Jay Mark A. Reños |
| Systems Analyst | Liza Mae C. Sugala |
| Lead Developer / Programmer | Rusel R. Portes |
| Database & QA Lead | Efren S. Cabudbud Jr. |
| UI/UX & Documentation Lead | Rayla G. Lanaza |

Adviser: Engr. Clark Kevin V. Villamor
