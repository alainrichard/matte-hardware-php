# MATTE Hardware — PHP / XAMPP

MATTE storefront and administration built with PHP 8.2+, HTML, CSS, vanilla JavaScript and MariaDB. The application runs without React, Next.js, Node.js or Docker. Node.js is optional for development tests and importing the previous PostgreSQL system.

## Features

- Responsive storefront, department navigation with grouped categories and product previews, search, filters and product galleries.
- English, French and Kinyarwanda flag selectors; light, dark and system themes.
- Customer accounts, persistent carts, checkout, delivery/pickup, optional TIN proformas and PDF invoices.
- Product, stock, category, order, customer, quote and site-content administration.
- Website chat, WhatsApp integration, MTN MoMo integration and queued email notifications.
- Transactional stock reservations, role checks, validated uploads and private configuration.

See [FEATURE_PARITY.md](FEATURE_PARITY.md) for implementation and verification coverage. External payments, WhatsApp and SMTP require your own credentials; they are disabled by default.

## Install with XAMPP

Run these commands from the repository root. Install XAMPP with PHP 8.2 or later and Composer. Enable PHP `pdo_mysql`, `mbstring`, `gd`, `curl`, `openssl` and `fileinfo`; `exif` is recommended for camera-photo orientation.

```powershell
git clone https://github.com/alainrichard/matte-hardware-php.git
cd matte-hardware-php
composer install --no-dev --prefer-dist
Copy-Item .env.example .env
C:\xampp\php\php.exe -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Edit the private `.env` file:

- Set `JWT_SECRET` to the generated value (at least 32 characters).
- Set `APP_URL=http://localhost:8188` for the Apache setup below.
- Set `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` and `DB_PASSWORD` for your MariaDB instance. XAMPP's default local account is `root` with an empty password.
- Set `ADMIN_EMAIL` and a strong `ADMIN_PASSWORD` of 12–72 bytes for initial administrator provisioning.

Start MySQL in XAMPP, then run:

```powershell
C:\xampp\php\php.exe scripts/create-database.php
C:\xampp\php\php.exe scripts/console.php init
C:\xampp\php\php.exe scripts/console.php admin
# Optional: a starter catalog for a NEW shop only.
C:\xampp\php\php.exe scripts/console.php seed
C:\xampp\php\php.exe scripts/console.php check
```

`admin` creates the configured administrator or resets that administrator's password. Do not run it against migrated accounts unless you intend to reset the password. Do not seed a database intended for migration. Starter prices remain unconfirmed until staff review them. Upload product photos through administration; existing customer uploads are not included in this repository.

### Apache

Edit `xampp/httpd-example.conf` to match your clone's absolute `public` directory. Include it from `C:/xampp/apache/conf/httpd.conf` using an absolute path, for example:

```apache
Include "C:/xampp/htdocs/matte-hardware-php/xampp/httpd-example.conf"
```

Check configuration with `C:\xampp\apache\bin\httpd.exe -t`, then start/restart Apache. Open **http://localhost:8188**, sign in at `/signin`, and manage the shop at `/admin`. Only `public` may be the web document root. The supplied virtual host binds to localhost.

### PHP development server

Alternatively, set `APP_URL=http://localhost:8080` and run:

```powershell
C:\xampp\php\php.exe -S localhost:8080 -t public router.php
```

This is a local development server. A public deployment needs an HTTPS host, appropriate database credentials, and provider callback configuration.

## Background jobs and integrations

Configure MTN MoMo, WhatsApp and SMTP in `.env` before enabling their corresponding flags. Public callbacks require reachable HTTPS URLs. Website chat uses HTTP polling; no Socket.io server is needed.

On Windows, inspect `scripts/worker.ps1` for your PHP path and run `scripts/register-worker.ps1` to register a once-per-minute worker for the signed-in user. The task name is `MATTE PHP payment and notification worker`. Existing tasks are not overwritten. On other hosts, schedule this command every minute:

```sh
php scripts/console.php worker
```

Disabled integrations do not send messages or initiate payments. Logs are written to private `storage`. Avoid running both the old and new payment workers against the same live transactions.

## Migrate the previous PostgreSQL system

The migration utilities require access to the original MATTE Node/PostgreSQL checkout or a source database. Migration snapshots and uploads are private and are intentionally absent from GitHub.

```powershell
npm ci
$env:MATTE_SOURCE_ROOT='D:\path\to\original-matte-hardware'
node scripts/export-docker.cjs storage/source-snapshot.json
C:\xampp\php\php.exe scripts/migrate-xampp.php storage/source-snapshot.json matte_store_next
```

The Docker exporter uses the source checkout's `.docker-local` configuration and `docker-compose.yml`. The importer creates a fresh local XAMPP target, rejects nonempty targets and unknown columns, verifies the source fields, then switches `.env` only after success. Review `migrate-xampp.php` for local connection assumptions before use.

For a direct PostgreSQL export, set `DATABASE_URL` in the process environment, or set `MATTE_SOURCE_ENV` to a private source configuration file, then run `node scripts/export-postgres.cjs storage/source-snapshot.json`. Copy the original uploads separately. Use `MATTE_ENV_FILE` for CLI import/check commands when custom MariaDB credentials are required.

`export-assets.cjs` is an optional one-time utility for copying assets from the original checkout; the bundled default assets are already present. It also copies source uploads, which remain ignored by Git.

After migration, the databases are independent. Stop writes to the old system for final cutover; later changes are not synchronized automatically. Keep private database and upload backups together.

## Development checks

```powershell
npm ci
# Browser tests use locally installed Microsoft Edge through Playwright.
npm run test:appearance
npm run test:departments
```

Appearance checks target `http://localhost:8188`; they verify flag/theme persistence, navigation and phone/tablet/desktop layouts. Development dependencies are not required in production.

API, administration and checkout tests create records: use a separate test database. Run `node tests/setup.cjs` to create `storage/test.env`, then:

```powershell
$env:MATTE_ENV_FILE=(Resolve-Path storage/test.env).Path
C:\xampp\php\php.exe scripts/create-database.php
C:\xampp\php\php.exe scripts/console.php init
C:\xampp\php\php.exe scripts/console.php seed
C:\xampp\php\php.exe scripts/console.php admin
C:\xampp\php\php.exe -S 127.0.0.1:8189 -t public router.php
# In another terminal:
$env:MATTE_TEST_ENV=(Resolve-Path storage/test.env).Path
npm run test:api
npm run test:browser
npm run test:checkout
```

The test configuration rejects database names that do not end in `_test`. Clear `MATTE_ENV_FILE` after testing before running commands against another database. `tests/migrated.cjs` and `tests/apache-concurrency.cjs` are local acceptance checks requiring private configured credentials; migrated checks also require the private source snapshot. They do not modify business records. `tests/payment.php` runs payment/webhook scenarios inside rolled-back transactions without external requests.

## Repository contents and private files

- `public/`: browser entry point, styles, JavaScript, flags and branding.
- `src/`: PHP application and server-rendered views.
- `database/schema.sql`: MariaDB schema.
- `data/`: default catalog, category tree, content and translations.
- `scripts/`: setup, migration and background jobs.
- `tests/`: verification tools.

Git excludes `.env`, dependencies, private storage, generated reports, snapshots, logs and `public/uploads`. No customer/order database, credentials or private invoices are published. Install dependencies and restore your own uploads/database when deploying an existing shop.
