# WIDMS deployment: commands and checklist

Use this folder as the complete application package. These commands do not require the original `WIDMS-proj` folder. Replace every placeholder with values supplied by your hosting provider. Never post passwords or this package publicly.

## A. Prepare the hosting server

1. Choose PHP/MySQL hosting, not static-only hosting. Upload the COMPLETE folder privately.
2. Set the domain document root to `/home/YOUR_ACCOUNT/widms/public` (use your actual path).
3. In the hosting panel enable PHP 8.1+ and PDO MySQL, mbstring, DOM/XML, fileinfo, cURL, OpenSSL, ZIP and GD. Configure the same extensions for command-line PHP.
4. Create a new EMPTY database and a dedicated database user in the hosting panel. Give that user the required schema/data privileges. Do not use a production root account.
5. Enable HTTPS for the domain in the hosting panel.

The commands below use a Linux SSH terminal. Commands may be named `mariadb` instead of `mysql`, or use a provider-specific PHP binary. Ask the host for these paths if they are not available. Hosting without SSH can use phpMyAdmin for import and the hosting panel's cron facility for CLI commands; do not expose CLI scripts as public web pages.

```sh
cd /home/YOUR_ACCOUNT/widms
php -v
php -m
```

## B. Configure database, email and SMS

Edit private `config/database.php` with the hosted DB host, port, database name, username and password. This is the simplest persistent setup. Alternatively configure the following variables in BOTH web PHP and CLI/cron environments:

```sh
export WIDMS_DB_HOST='YOUR_DB_HOST'
export WIDMS_DB_PORT='3306'
export WIDMS_DB_NAME='YOUR_DATABASE'
export WIDMS_DB_USER='YOUR_DB_USER'
read -r -s -p 'Database password: ' WIDMS_DB_PASS; echo
export WIDMS_DB_PASS
```

These shell exports apply only to the current terminal and child commands, not automatically to your website or future cron jobs. A `.env` file alone is not read by this application. Never leave the local root/blank-password defaults on public hosting.

The copied `config/smtp.local.php` and `config/sms.local.php` contain the original private integration configuration. Check that the accounts are still valid on the new server. These files override SMTP/SMS environment variables. Allow outbound HTTPS to Textit.biz and the configured SMTP host/port. SMS requires provider credit; email requires valid SMTP credentials and TLS. Never disable certificate verification to bypass a hosting TLS problem.

## C. Preserve ALL existing accounts and data (recommended for your move)

Use the included `database/current-data.sql`. It is the snapshot taken on 20 October 2026; later localhost changes are not automatically included. Before final cutover, stop users from making changes and take a new database export plus a matching copy of `public/uploads` if data has changed.

**WARNING: Import only into an empty destination database. The dump contains table replacement statements. Do not import it into the current live database or a populated hosting database without a verified backup.**

```sh
mysql --host=YOUR_DB_HOST --port=3306 --user=YOUR_DB_USER --password YOUR_DATABASE < database/current-data.sql
```

Enter the database password when prompted. Alternatively select the empty database in phpMyAdmin and import `current-data.sql`.

Then run:

```sh
php database/migrate.php --check
php database/migrate.php --status
php database/verify.php
php scripts/check-runtime.php
php scripts/send-notification-sms.php --check
```

`--check`, `--status`, verification and runtime diagnostics do not create/delete users or send messages. If any command fails, STOP and resolve it before launch. Check the command's exit status as well as its printed output.

The backup contains views with the original MySQL DEFINER. If import fails with DEFINER/SUPER/access errors, have the host assign the views to the destination database account or provide an approved compatible export. Do not ignore a partial import.

No pending migrations should be reported for the included snapshot. If a later deployment reports pending migrations, back up first, review them, then apply:

```sh
php database/migrate.php
php database/verify.php
```

The runner attempts `CREATE DATABASE IF NOT EXISTS`; on restricted hosting this may require provider assistance even for a pre-created database. Never run all SQL files manually. Do not run `seed.sql` or historical data-clearing migrations over restored data.

**Admin login:** importing the backup preserves the existing username and password hash. Use your current admin login. Migration does not reset its password or create another admin when users exist.

## D. Alternative: a brand-new empty installation

Skip this section if you imported existing data. Do NOT import `current-data.sql` for a fresh installation.

```sh
php database/migrate.php --check
php database/migrate.php
php database/verify.php
php scripts/check-runtime.php
```

In an interactive terminal, migration asks for admin email, Sri Lankan mobile number, password and confirmation. Password input is visible. It creates one active admin with a hashed password only when the users table is empty. First-admin setup does not send the password by SMS. Geography import needs internet access.

For a non-interactive environment, set `WIDMS_ADMIN_USERNAME`, `WIDMS_ADMIN_PHONE` and `WIDMS_ADMIN_PASSWORD` securely before the command, then remove them afterwards. Never store the bootstrap password in a public script.

## E. PHP dependencies and permissions

`vendor/` is already included. If Composer is available, validate the destination:

```sh
composer check-platform-reqs
```

If dependencies need rebuilding, use the lock file:

```sh
composer install --no-dev --prefer-dist --optimize-autoloader
```

Grant the web PHP account write access to `public/uploads`, the Dompdf font cache (`vendor/dompdf/dompdf/lib/fonts`) and PHP's configured temporary/session locations. Ownership differs by host: use its file manager or permission guidance. Do not use blanket `chmod 777`. Both web and CLI checks must use accounts with the appropriate permissions.

Configure the server to prevent script execution in uploaded files. Keep application/configuration files read-only to the web process wherever possible. Private folders must remain outside the web document root. Apache `.htaccess` files are additional protection only, not a replacement for correct web-root configuration.

## F. Notifications and optional background SMS processing

The application schedules bounded SMS processing after eligible form submissions. Reading a page does not send messages. For ongoing background processing, add ONE scheduler for the active deployment. Do not run local and hosted senders against the same database during testing.

Read-only queue status:

```sh
php scripts/send-notification-sms.php --check
```

The following actually sends queued messages and can incur charges. Run only when you are ready for live delivery:

```sh
php scripts/send-notification-sms.php --send
```

Example cron entry, once per minute (replace paths and ensure cron has the database configuration):

```cron
* * * * * /usr/bin/php /home/YOUR_ACCOUNT/widms/scripts/send-notification-sms.php --send >> /home/YOUR_ACCOUNT/widms-sms.log 2>&1
```

Keep logs outside `public`, restrict their permissions and configure log rotation. Do not blindly resend `unknown` jobs: the provider may already have delivered them. A configured account or `sent` gateway response does not prove that a recipient received the message. Test with an approved recipient before general launch.

## G. Final browser checks on the hosted domain

1. Open `https://YOUR_DOMAIN/login.php`; verify all styles/images load.
2. Sign in with each existing role. Check menus and permissions.
3. With approved test data, verify registration/approval, aid requests, stock receipt/dispatch, distribution, returns, camps and supplier/payment workflows that your organization uses.
4. Check uploaded documents, reports, PDF/CSV/Excel export and printing, including any Sinhala/Tamil text you use.
5. Perform an approved email/SMS test and check provider delivery records.
6. Confirm SQL backups, configuration files, logs and private PHP folders are NOT downloadable. Move `current-data.sql` to secure backup storage after importing.
7. Disable public PHP error display, keep private error logs, and schedule regular database AND upload backups.

Some pages load Bootstrap from a CDN. Browsers require internet access to that CDN; SMS/email require server network access. The hosted system does not need your PC switched on once the application, database and uploads are transferred to the server.

## H. Check the new local copy in Windows XAMPP

Start Apache and MySQL using XAMPP, then in PowerShell:

```powershell
Set-Location 'D:\Program Files\xampp_new\htdocs\widms'
& 'D:\Program Files\xampp_new\php\php.exe' database/migrate.php --check
& 'D:\Program Files\xampp_new\php\php.exe' database/migrate.php --status
& 'D:\Program Files\xampp_new\php\php.exe' database/verify.php
& 'D:\Program Files\xampp_new\php\php.exe' scripts/check-runtime.php
& 'D:\Program Files\xampp_new\php\php.exe' scripts/send-notification-sms.php --check
```

Open `http://localhost/widms/public/login.php`. This local copy currently shares the original `widms` database; do not reimport the backup into it.

The audit found GD disabled in local PHP. To enable it, back up `D:\Program Files\xampp_new\php\php.ini`, change `;extension=gd` to `extension=gd`, and restart Apache in XAMPP. Then rerun the runtime check. This is a server setting, not a missing project file. The hosting server must enable its own GD extension independently.
