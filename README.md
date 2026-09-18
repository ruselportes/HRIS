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
| Central DB | MySQL 8.0 (local dev in Docker) |
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

The backend, database and web portal run in Docker ([`compose.yaml`](compose.yaml)); this replaced XAMPP. The mobile app runs on the host as before.

### Backend, database and web (Docker)

Requires [Docker Desktop](https://www.docker.com/products/docker-desktop/) (WSL 2 on Windows).

```bash
docker compose up -d                           # first run builds and installs; takes a few minutes
docker compose exec api php artisan db:seed    # first run only: sample data
```

| Service | URL | Notes |
|---|---|---|
| Laravel API | http://localhost:8090 | migrations run on every start |
| Web portal | http://localhost:5173 | hot reload |
| phpMyAdmin | http://localhost:8081 | signs in automatically |
| MySQL 8.0 | `127.0.0.1:3307` | user `hris` / `secret`, database `hris` (dev only) |

A scheduler container runs Laravel's scheduler, which ends acting foreman covers on time.

Everyday commands:

```bash
docker compose exec api php artisan test      # tests use in-memory SQLite, never the dev database
docker compose exec api php artisan tinker
docker compose logs -f api
docker compose down                           # stop; the database is kept in the mysql-data volume
docker compose down -v                        # stop and DELETE the database and installed packages
```

`backend/.env` is still read inside the containers for everything except the database, whose `DB_*` values the containers set themselves. Its own `DB_*` lines point at the Docker MySQL on port 3307, for running `php artisan` on the host.

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
| Documentation Lead | Rayla G. Lanaza |
| UI/UX|Carl Vey Sente|


