# WIDMS hosting package

Prepared on 20 October 2026 from `WIDMS-proj/WIDMS`. The original project and its live database were not modified by packaging.

## Contents

- `public/`: website entry pages, assets, uploaded documents and existing PDF manuals.
- `modules/`, `includes/`, `config/`: application and configuration files.
- `vendor/`, `composer.json`, `composer.lock`: installed PHP dependencies and their version lock.
- `database/`: all migration SQL files, schema, seed, migration runner, verification, geography importer and a fresh `current-data.sql` export.
- `scripts/send-notification-sms.php`: optional SMS queue worker.

The existing private SMTP/SMS configuration has been included so this copy has the same integration settings as the original. Treat the entire package as confidential. Recovery tools, old backups, tests, browser sessions and manual-building source assets are excluded. Uploaded documents and the database export contain private operational data. Transfer them securely; never publish the SQL export or configuration files.

**Start here:** Follow [DEPLOYMENT_COMMANDS.md](DEPLOYMENT_COMMANDS.md) for exact commands. To preserve your current system, choose **existing-data deployment**, not fresh installation. No files from `WIDMS-proj` are needed after deployment; the web server, database and external email/SMS services still need to be available and configured.

## 1. Hosting requirements and folders

Use PHP 8.1 or newer compatible with the installed dependencies and MySQL/MariaDB. Enable PDO MySQL, mbstring, DOM/XML and fileinfo; enable cURL for integrations and GD for PDF images where needed. Run `composer check-platform-reqs` on the host when Composer is available.

Preserve this directory structure. Set the website document root to the `public/` folder, not the package root. Keep `config`, `database`, `modules`, `includes`, `vendor` and `scripts` outside the public document root. For shared hosting, ask the provider how to point the domain at `public/`; do not move only the PHP entry files without updating their relative dependencies.

For local inspection only, the login URL is `http://localhost/widms/public/login.php`. This copy uses the same database defaults as the original until you configure a different database. Do not submit changes from the copy if you do not want them applied to that shared database.

Apache `.htaccess` files deny browser access to private directories as an extra safeguard. They require Apache to allow those directives and do not protect Nginx installations. Always configure the correct public document root. Test that private files cannot be downloaded before going live.

## 2. Configure the database

Create a database and a dedicated database user in the hosting panel. Set these environment variables for both web PHP and command-line PHP:

```
WIDMS_DB_HOST=your-database-host
WIDMS_DB_PORT=3306
WIDMS_DB_NAME=your-hosted-database
WIDMS_DB_USER=your-hosted-database-user
WIDMS_DB_PASS=your-secret-database-password
```

Alternatively edit the defaults in the private `config/database.php`. The application does not automatically load a `.env` file. Never use the local root/blank-password defaults on a public host.

## 3. Choose existing data OR a fresh installation

### Preserve existing users and records

Import `database/current-data.sql` into an EMPTY hosted database using phpMyAdmin or the host's import tool. This is a full database backup, not an additive merge: importing into a populated database can replace its tables. Back up any destination data first. Existing users and password hashes are preserved; no new administrator is created merely by importing the backup.

Keep the matching `public/uploads/` documents. The export includes database objects; if the host rejects a view/routine DEFINER or restricted privilege, stop and resolve that with the provider rather than continuing a partial import.

After import, from the package root run:

```sh
php database/migrate.php --status
php database/verify.php
```

Review pending migrations before applying them. The runner attempts `CREATE DATABASE IF NOT EXISTS`, so restrictive hosting accounts may require provider assistance even when a database has already been provisioned.

### Start with an empty system

Do NOT import `current-data.sql`. Run from a terminal in the package root:

```sh
php database/migrate.php --check
php database/migrate.php
php database/verify.php
```

For an empty users table, migration asks for the first administrator's email username, Sri Lankan mobile number and a password of at least eight characters, with confirmation. The terminal password input is visible. It saves an active admin account with a hashed password. Sign in using that email and the password you supplied; there is no default admin password.

For a non-interactive terminal, securely configure `WIDMS_ADMIN_USERNAME`, `WIDMS_ADMIN_PHONE`, and `WIDMS_ADMIN_PASSWORD` before migration and remove those bootstrap variables afterwards. If ANY users already exist, automatic first-admin creation is skipped; migration does not replace accounts or reset passwords. This setup does not send the admin password by SMS.

Initial geography import requires internet access when the geography tables are empty. See `database/README.md` for details.

### Important migration safety

All original `migration_*.sql` files are included for completeness, but only the ordered list in `migrate.php` is meant to run automatically. Do not import every SQL file, run retired cleanup migrations, or import `seed.sql` blindly. Some historical files remove data or add old defaults. Never import `schema.sql` over an existing production database as a substitute for migration.

## 4. Email, SMS and dependencies

Configure SMTP using `WIDMS_SMTP_HOST`, `WIDMS_SMTP_PORT`, `WIDMS_SMTP_ENCRYPTION`, `WIDMS_SMTP_USERNAME`, `WIDMS_SMTP_PASSWORD`, `WIDMS_SMTP_FROM_EMAIL`, and `WIDMS_SMTP_FROM_NAME`.

For SMS set `WIDMS_SMS_ENABLED=true`, `WIDMS_SMS_USER_ID`, and `WIDMS_SMS_PASSWORD`, or use the included private `config/sms.local.php`. The copied `smtp.local.php` and `sms.local.php` override environment settings. Verify/update those private files for the host, or remove them securely if using environment configuration instead. SMS can incur charges. Account validity, credit and outbound network access must be checked on the host.

If rebuilding dependencies, run `composer install --no-dev --prefer-dist --optimize-autoloader`. The included `vendor/` already contains the current installed dependencies.

If scheduled SMS processing is wanted, configure the host scheduler with the correct PHP binary, absolute script path and environment:

```sh
php scripts/send-notification-sms.php --check
php scripts/send-notification-sms.php --send
```

`--check` reads queue status; `--send` actually queues/sends messages. Do not enable sending on a duplicate test deployment connected to the live database.

## 5. Before going live

Enable HTTPS, disable display of PHP errors to visitors, and keep private error logs. Grant write access only where required, such as `public/uploads` and PHP session/temp storage; do not use blanket 777 permissions. Configure the host to prevent uploaded scripts from executing.

Check login for each role, uploaded-document access, PDF/CSV exports and the configured communication services using approved test records. Verify private URLs return access denied. Move the database export into secure backup storage after import. This package was copied and checked locally; it has not been deployed or validated on your hosting provider.

## Local audit on 20 October 2026

- Compared 903 runtime files against the original: no missing or changed files at packaging time.
- All 143 original application/setup PHP files passed syntax checks; all 495 checked literal file references were present.
- All 77 migration SQL files are present. The runner validates its 68 selected schema/migration files; historical cleanup files are deliberately not executed.
- Database verification and report queries passed; no migrations were pending. One active administrator exists.
- Basic PDF generation, PHP mail library loading, directory permissions and simulated SMS-response handling passed. Simulation did not contact recipients.
- Local CLI PHP currently lacks GD (`extension=gd` is commented out in XAMPP's php.ini). Enable GD on the hosting server for full image/PDF support. Other checked extensions are present locally.
- Existing SMS queue has 210 `unknown` entries. These are not proof of failed delivery and are not automatically retried, to avoid duplicate messages. Check gateway records before any retry.
- Real email/SMS delivery and every business workflow have NOT been tested on a hosting server. A successful local audit is not a guarantee of server configuration or gateway availability.

Run `php scripts/check-runtime.php` on the destination for repeatable diagnostics. It checks configuration presence without printing credentials, sends no messages, and does not modify operational records.
