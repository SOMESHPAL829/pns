# KARAM App Portal

PHP and MySQL starter app based on the KARAM theme. It includes server-backed sign-in, one-time password recovery, profile and password updates, admin-managed users, and owner-scoped record CRUD.

## Requirements

- PHP 8.1+ with PDO MySQL
- MySQL 8+ or compatible MariaDB
- A configured PHP mail transport for password-reset email

## Configure

1. Copy `config.example.php` to `config.php`.
2. Set the MySQL connection values in `config.php`. This file is ignored by Git.
3. Set `APP_BASE_URL` to the public app URL and `APP_MAIL_FROM` to a sender address in the PHP environment.
4. Ensure the database account can create and use the `portal_*` tables.
5. From this directory, run `php setup.php admin@example.com admin "Portal Administrator"` and enter a password when prompted. The installer creates the tables and refuses to create a second initial admin.

The account recovery page returns the same confirmation for existing and unknown email addresses. Reset links expire after 30 minutes and can only be used once. PHP's `mail()` must be connected to a working mail transport for delivery.

## Run locally

From this directory:

```bash
php -S 127.0.0.1:4173
```

Open `http://127.0.0.1:4173/`. Do not use `python -m http.server`; PHP routes are required for authentication and database access.

## App screens

- `#/dashboard` — current user's recent records and summary
- `#/requests` — searchable, filterable record list
- `#/create` and `#/detail` — create and update records
- `#/users` and `#/user-create` — admin-only account management
- `#/profile` — update profile and change password

Administrators create accounts; there is no public sign-up route. Regular users can view and change only their own records. Administrators can manage users and view all records.

## Security notes

- Passwords use PHP's password hashing API; reset tokens are stored as hashes.
- Session cookies are HttpOnly and SameSite=Lax; unsafe requests require CSRF tokens.
- Use HTTPS in production. Configure the database and mail transport outside version control.
- Review and customize the sample record types, organization choices, and categories for your app.

## Anodizing OEE cloud upgrade

The OEE architecture and rollout plan are documented in
`OEE_CLOUD_UPGRADE_PLAN.md`. The first additive database migration is
`migrations/001_oee_foundation.sql`; it creates only `oee_*` tables and does
not modify the existing portal tables.

Before enabling Unit 1 ingestion, configure `OEE_INGEST_API_KEY` in the
server environment. Never place the key in `config.php`, source control, or
the Unit 1 report payload. The authenticated endpoint is now available at
`api/v1/ingest/scada-report.php`; it remains disabled until the migration and
server-side API key are configured. The next implementation slice updates
Unit 1's sender.

The authenticated app now includes:

- `#/dashboard` — OEE KPIs, production summary, time losses, sync health, and recent imports.
- `#/oee-report` — date-filtered flight-bar production report.
- `#/scada-logs` — Unit 1 sync status and cloud import event log.

## Theme assets

`css/theme.css` contains design tokens. `css/portal.css` contains the shared layout and components. `js/icons.js` contains Heroicons Outline icons (MIT), and `js/portal.js` handles navigation and toasts. Add icons with `<span class="app-sidebar-icon" data-icon="users"></span>`.
