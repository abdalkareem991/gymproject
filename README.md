# Plus Ultra Gym

Vanilla HTML, CSS, and JavaScript front end with PHP 8 and MySQL JSON APIs.

## Project structure

- `modules/landing/`: public landing page and its dedicated styles; shared browser behavior lives in `shared/js/`.
- `modules/auth/`: login and registration pages and their assets.
- `modules/admin/`, `modules/staff/`, `modules/member/`, `modules/classes/`, and `modules/trainer/`: role and feature pages with their page-specific styles/scripts.
- `shared/`: shared logo, styles, and browser scripts.
- `backend/api/`: PHP JSON endpoints.
- `backend/config/`, `backend/database/`, `backend/helpers/`, and `backend/storage/`: PHP configuration, SQL schema/migrations, shared helpers, and runtime storage.
- `tests/`: local PHP checks.

## Local setup

1. Place the project under the XAMPP web root and open it through Apache, for example `http://localhost/gymproject/`. Do not use VS Code Live Server (usually port `5500`): it serves `.php` files as static content and cannot run the API.
2. For an existing installation, preserve its data and apply reviewed database changes using a backup and the current live schema as the reference. The tracked `backend/database/schema.sql` is a structure-only export (no user, plan, or other application data); importing it is not a data migration and must not be used to overwrite a populated database.
3. A fresh installation also needs application seed data (at minimum an administrator and membership plans). This repository currently does not contain a seed-data script; create those records through a separately reviewed setup procedure before relying on the app.
4. The payment settings schema includes safe defaults, but the initial singleton row is application data. After importing the structure into a new database, insert a settings row with cash and bank transfer enabled and online card payments disabled, or create it through an approved setup procedure.
5. Set `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD` for Apache. Defaults are `127.0.0.1`, `3306`, `smart_gym`, `root`, and an empty password.
6. Restart Apache after changing its environment.

The PDO connection is centralized in `backend/config/db.php` and uses `full_name`, `member_id`, `start_date`/`end_date`, and plan `title` columns. Do not assume a sample administrator or plans exist in a structure-only schema export.

Apache must honor `.htaccess` overrides for this project. The root rules add security headers, disable directory listings, and preserve the original page and API URLs while serving the reorganized files. `backend/config/`, `backend/database/`, `backend/helpers/`, and `backend/storage/` deny direct web requests while remaining available to PHP includes.

## Application pages

- `modules/landing/index.html`: public landing page, available at `/` and `/index.html`.
- `modules/auth/login.html` and `modules/auth/register.html`: session-based account access, available at `/login.html` and `/register.html`.
- `modules/member/member.html`: membership overview.
- `modules/classes/classes.html`: member class schedule and bookings.
- `modules/staff/staff.html`: reception check-in and cash payments.
- `modules/trainer/trainer.html`: class scheduling, rosters, and attendance.
- `modules/admin/admin.html`: administrator analytics, payment history, and CSV reports.

The existing public routes (`member.html`, `classes.html`, `staff.html`, `trainer.html`, and `admin.html`) continue to work. API requests continue to use `/api/...` and are internally served from `backend/api/`.

## API layout

- `backend/api/auth/`: registration, login, logout, and session/CSRF state.
- `backend/api/member/`: member dashboard.
- `backend/api/staff/`: member lookup, manual check-in, configured cash or bank-transfer payments, and reception overview.
- `backend/api/admin/payment-settings.php`: administrator-only payment method settings. Online card payments stay disabled until a provider adapter and server-side credentials are installed.
- `backend/api/member/payment-checkout.php` and `backend/api/payments/webhook.php`: hosted checkout and idempotent verified settlement contracts for a future selected gateway.
- `backend/api/classes/`: member class schedule, booking, and booking cancellation.
- `backend/api/trainer/`: class scheduling/cancellation, trainer rosters, and attendance.
- `backend/api/admin/`: operational analytics and date-bounded payment/attendance CSV exports.

Protected write requests use the CSRF token returned by `api/auth/session.php` in the `X-CSRF-Token` request header. New registrations receive the `MEMBER` role. Create or promote trusted staff and trainer accounts through an administrator-controlled process; public registration never accepts a role value.

Class capacity and trainer time conflicts are checked in the API. Class times are stored in UTC and shown in each browser's local timezone. Staff, trainer, and administrator permissions are checked against the active database account on each protected API request.

The administrator dashboard can provision trainer and reception accounts, create membership plans, find members, and activate a membership with its payment in one database transaction. Reception staff can record both check-in and check-out.

Employee initial passwords are chosen by an administrator and must be shared directly with the employee. Serve the application over HTTPS outside local development.

Chart.js 4.5.1 is loaded from its UMD CDN distribution on the administrator dashboard. CSV reports are restricted to administrator accounts, use UTC date filters, and neutralize spreadsheet formula prefixes in text cells.

## Verification

With Apache and MySQL running, run `php tests/run.php` for unit tests, `php tests/integration-auth.php` for the authentication flow, `php tests/integration-operations.php` for plan, membership, payment, team, booking, and reception flows, and `php tests/ui-smoke.php` for page and local asset HTTP checks. Integration suites use temporary records and clean up their fixtures.
